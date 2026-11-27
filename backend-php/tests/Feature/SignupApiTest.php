<?php

namespace Tests\Feature;

use App\Exceptions\EmailException;
use App\Models\Department;
use App\Models\EmailCode;
use App\Models\PendingSignup;
use App\Models\Section;
use App\Models\User;
use App\Services\EmailSender;
use App\Services\Security;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Port of #93's backend/tests/test_signup.py. Emails aren't sent: EmailSender is
 * replaced by an outbox, and the tests read the 6-digit code out of the text,
 * exactly like a person would.
 */
class SignupApiTest extends TestCase
{
    private const STUDENT = 'numl-f24-26789@numls.edu.pk';

    private const STAFF = 'ali.khan@numls.edu.pk';

    private const PASSWORD = 'long-enough-1';

    /** @var list<array{to: string, text: string}> */
    private array $outbox = [];

    private bool $mailFails = false;

    private Department $se;

    private Department $cs;

    private Section $sec;

    private Section $other;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->instance(EmailSender::class, new class($test) extends EmailSender
        {
            public function __construct(private SignupApiTest $test) {}

            public function send(string $to, string $subject, string $text): void
            {
                $this->test->record($to, $text);
            }
        });

        $this->se = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        $this->cs = Department::factory()->create(['name' => 'Computer Science', 'code' => 'CS']);
        $this->sec = Section::factory()->create(['name' => 'BSSE-5A', 'department_id' => $this->se->id]);
        $this->other = Section::factory()->create(['name' => 'BSCS-3B', 'department_id' => $this->cs->id]);
    }

    /** Called by the fake sender. */
    public function record(string $to, string $text): void
    {
        if ($this->mailFails) {
            throw new EmailException('provider down');
        }
        $this->outbox[] = ['to' => $to, 'text' => $text];
    }

    // ------------------------------------------------------------ signup options

    public function test_signup_options_lists_departments_and_sections(): void
    {
        $data = $this->getJson('/api/auth/signup-options')->assertOk()->json();

        $this->assertSame(['CS', 'SE'], array_column($data['departments'], 'code'));   // by name
        $this->assertEqualsCanonicalizing(
            ['BSSE-5A' => $this->se->id, 'BSCS-3B' => $this->cs->id],
            array_column($data['sections'], 'department_id', 'name'),
        );
    }

    // ------------------------------------------------------------ student and staff signup

    public function test_student_signup_creates_a_logged_in_student(): void
    {
        $this->postJson('/api/auth/signup', $this->studentBody())->assertStatus(202)
            ->assertExactJson(['email' => self::STUDENT, 'kind' => 'student', 'expires_in' => 600]);
        $this->assertSame(0, User::count());   // nothing is an account before the code

        $res = $this->verify(self::STUDENT)->assertOk();
        $user = $res->json('user');
        $this->assertSame(['student', 'numl-f24-26789'], [$user['role'], $user['identifier']]);
        $this->assertSame([$this->sec->id, $this->se->id], [$user['section_id'], $user['department_id']]);

        $this->withToken($res->json('token'))->getJson('/api/auth/me')->assertOk()->assertJsonPath('id', $user['id']);
        $row = User::find($user['id']);
        $this->assertSame([self::STUDENT, 'se-9244330', '26789'], [$row->email, $row->roll_number, $row->sys_id]);
        $this->assertNotNull($row->email_verified_at);
        $this->assertNotSame(self::PASSWORD, $row->password_hash);
        $this->assertSame([0, 0], [PendingSignup::count(), EmailCode::count()]);   // the code is single use
    }

    public function test_staff_signup_is_a_teacher_with_no_approval_step(): void
    {
        $this->assertSame('staff', $this->postJson('/api/auth/signup', $this->staffBody())->json('kind'));

        $user = $this->verify(self::STAFF)->assertOk()->json('user');
        $this->assertSame(['teacher', self::STAFF], [$user['role'], $user['identifier']]);
        $this->assertNull($user['teacher_id']);   // an admin links them later
        $this->assertNull($user['section_id']);
        $this->login(self::STAFF)->assertOk();    // can log in at once
    }

    public function test_role_is_never_taken_from_the_request(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody(['role' => 'admin']));

        $this->assertSame('teacher', $this->verify(self::STAFF)->json('user.role'));
    }

    public function test_login_works_with_the_id_or_the_full_email(): void
    {
        $this->postJson('/api/auth/signup', $this->studentBody());
        $this->verify(self::STUDENT);

        $this->login('numl-f24-26789')->assertOk();
        $this->login('  NUML-F24-26789@numls.edu.pk ')->assertOk();
        $this->login(self::STUDENT, 'wrong-password')->assertUnauthorized()->assertExactJson(['detail' => 'Invalid credentials']);
    }

    #[DataProvider('foreignEmails')]
    public function test_only_numl_emails_can_sign_up(string $email): void
    {
        $res = $this->postJson('/api/auth/signup', $this->studentBody(['email' => $email]))->assertStatus(400);

        $this->assertStringContainsString('NUML email', $res->json('detail'));
        $this->assertSame([], $this->outbox);
    }

    public static function foreignEmails(): array
    {
        return [['sara@gmail.com'], ['numl-f24-1@numls.edu.pk.evil.com'], ['a b@numls.edu.pk'], ['@numls.edu.pk'],
            ["numl-f24-1@numls.edu.pk\nx"]];
    }

    #[DataProvider('badStudentDetails')]
    public function test_student_details_are_checked(array $changes, string $message): void
    {
        $res = $this->postJson('/api/auth/signup', $this->studentBody($changes))->assertStatus(422);

        $this->assertStringContainsString($message, $res->json('detail'));
        $this->assertSame([], $this->outbox);
    }

    public static function badStudentDetails(): array
    {
        return [
            [['roll_number' => '9244330'], 'Roll number'],
            [['roll_number' => null], 'Roll number'],
            [['section_id' => null], 'section'],
            [['section_id' => 9999], 'section'],
        ];
    }

    public function test_section_must_belong_to_the_chosen_department(): void
    {
        $this->postJson('/api/auth/signup', $this->studentBody(['section_id' => $this->other->id]))->assertStatus(422);
    }

    public function test_unknown_department_and_short_password_are_refused(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody(['department_id' => 9999]))->assertStatus(422)
            ->assertExactJson(['detail' => 'Choose your department.']);
        $this->postJson('/api/auth/signup', $this->staffBody(['password' => 'short']))->assertStatus(422);
        $this->postJson('/api/auth/signup', $this->staffBody(['password' => str_repeat('a', 73)]))->assertStatus(422);
    }

    public function test_an_email_that_has_an_account_gets_409(): void
    {
        User::factory()->role('teacher')->create(['identifier' => self::STAFF, 'email' => self::STAFF]);

        $res = $this->postJson('/api/auth/signup', $this->staffBody())->assertStatus(409);
        $this->assertStringContainsString('already has an account', $res->json('detail'));
        $this->assertSame([], $this->outbox);
    }

    // ------------------------------------------------------------ codes

    public function test_codes_are_stored_hashed(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody());

        $row = EmailCode::sole();
        $this->assertStringNotContainsString($this->lastCode(self::STAFF), $row->code_hash);
        $this->assertSame(64, strlen($row->code_hash));
    }

    public function test_wrong_code_then_lockout_after_five_tries(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody());
        $good = $this->lastCode(self::STAFF);
        $bad = $good !== '000000' ? '000000' : '111111';

        for ($i = 0; $i < 4; $i++) {
            $this->verify(self::STAFF, $bad)->assertStatus(400);
        }
        $this->verify(self::STAFF, $bad)->assertStatus(429);
        // even the right code is refused once the limit is reached
        $this->verify(self::STAFF, $good)->assertStatus(429)->assertExactJson(['detail' => 'Too many attempts. Try again later.']);
    }

    public function test_expired_code_is_refused(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody());
        $code = $this->lastCode(self::STAFF);
        $this->makeOlder(EmailCode::class, 11 * 60, self::STAFF);

        $this->verify(self::STAFF, $code)->assertStatus(400);
    }

    public function test_expired_signup_must_start_again(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody());
        $code = $this->lastCode(self::STAFF);
        $this->makeOlder(PendingSignup::class, 11 * 60, self::STAFF);

        $res = $this->verify(self::STAFF, $code)->assertStatus(400);
        $this->assertStringContainsString('Start again', $res->json('detail'));
    }

    public function test_verify_without_a_signup_is_refused(): void
    {
        $this->postJson('/api/auth/verify-email', ['email' => self::STAFF, 'code' => '123456'])->assertStatus(400);
    }

    public function test_resend_waits_a_minute_and_replaces_the_old_code(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody());
        $old = $this->lastCode(self::STAFF);

        $this->postJson('/api/auth/resend-code', ['email' => self::STAFF])->assertStatus(429)
            ->assertExactJson(['detail' => 'Please wait a minute before asking for a new code.']);
        $this->makeOlder(EmailCode::class, 61, self::STAFF);
        $this->postJson('/api/auth/resend-code', ['email' => self::STAFF])->assertStatus(202);
        $new = $this->lastCode(self::STAFF);
        if ($new !== $old) {   // 1 in a million they match
            $this->verify(self::STAFF, $old)->assertStatus(400);
        }
        $this->verify(self::STAFF, $new)->assertOk();
    }

    public function test_resend_without_a_signup_is_refused(): void
    {
        $this->postJson('/api/auth/resend-code', ['email' => self::STAFF])->assertStatus(400);
    }

    public function test_signup_again_within_a_minute_is_refused(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody())->assertStatus(202);
        $this->postJson('/api/auth/signup', $this->staffBody())->assertStatus(429);   // can't flood an inbox

        $this->assertCount(1, $this->outbox);
    }

    public function test_signup_again_later_replaces_the_unfinished_one(): void
    {
        $this->postJson('/api/auth/signup', $this->staffBody(['name' => 'First']));
        $this->makeOlder(EmailCode::class, 61, self::STAFF);
        $this->postJson('/api/auth/signup', $this->staffBody(['name' => 'Second']))->assertStatus(202);

        $this->assertSame('Second', $this->verify(self::STAFF)->json('user.name'));
        $this->assertSame(0, PendingSignup::count());
    }

    public function test_if_the_email_cannot_be_sent_nothing_is_saved(): void
    {
        $this->mailFails = true;

        $this->postJson('/api/auth/signup', $this->staffBody())->assertStatus(503)
            ->assertExactJson(['detail' => "We couldn't send the email right now. Try again in a minute."]);
        $this->assertSame([0, 0], [PendingSignup::count(), EmailCode::count()]);
    }

    // ------------------------------------------------------------ forgot password

    public function test_forgot_password_gives_the_same_answer_for_everyone(): void
    {
        $this->member();
        User::factory()->role('student')->create(['identifier' => 'se-111111']);   // roster account: no email

        foreach (['numl-f24-26789', 'nobody-here', 'se-111111'] as $who) {   // known, unknown, no email
            $this->postJson('/api/auth/forgot-password', ['identifier' => $who])->assertStatus(202)
                ->assertExactJson(['detail' => 'If that account exists, we sent a code to its email.']);
        }
        $this->assertSame([self::STUDENT], array_column($this->outbox, 'to'));   // only the real account got an email
    }

    public function test_reset_password_changes_it_and_logs_out_old_tokens(): void
    {
        $token = $this->member();
        $this->postJson('/api/auth/forgot-password', ['identifier' => self::STUDENT]);
        $code = $this->lastCode(self::STUDENT);

        $this->postJson('/api/auth/reset-password', ['identifier' => 'numl-f24-26789', 'code' => $code, 'new_password' => 'brand-new-pass'])
            ->assertOk()->assertExactJson(['detail' => 'Password changed. You can log in now.']);

        $this->login(self::STUDENT)->assertUnauthorized();                     // old password
        $this->login(self::STUDENT, 'brand-new-pass')->assertOk();             // new password
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();   // signed out everywhere
        // the code is single use
        $this->postJson('/api/auth/reset-password', ['identifier' => self::STUDENT, 'code' => $code, 'new_password' => 'another-pass1'])
            ->assertStatus(400);
    }

    public function test_reset_with_a_wrong_code_or_unknown_account_is_refused(): void
    {
        $this->member();
        $this->postJson('/api/auth/forgot-password', ['identifier' => self::STUDENT]);
        $good = $this->lastCode(self::STUDENT);
        $bad = $good !== '000000' ? '000000' : '111111';
        $body = ['identifier' => self::STUDENT, 'code' => $bad, 'new_password' => 'brand-new-pass'];

        $this->postJson('/api/auth/reset-password', $body)->assertStatus(400);
        $this->postJson('/api/auth/reset-password', [...$body, 'identifier' => 'nobody', 'code' => $good])->assertStatus(400)
            ->assertExactJson(['detail' => 'The code is wrong or has expired. Ask for a new one.']);
        $this->login(self::STUDENT)->assertOk();   // password unchanged
    }

    public function test_forgot_password_sends_at_most_one_code_a_minute(): void
    {
        $this->member();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/forgot-password', ['identifier' => self::STUDENT])->assertStatus(202);
        }
        $this->assertCount(1, $this->outbox);
    }

    public function test_forgot_password_still_answers_202_if_the_email_fails(): void
    {
        $this->member();
        $this->mailFails = true;

        $this->postJson('/api/auth/forgot-password', ['identifier' => self::STUDENT])->assertStatus(202);
        $this->assertSame(0, EmailCode::count());   // no code saved that nobody received
    }

    // ------------------------------------------------------------ the email sender

    public function test_brevo_sender_posts_to_the_api(): void
    {
        config(['timetable.email' => ['provider' => 'brevo', 'brevo_api_key' => 'key-123', 'from' => 'team@example.com', 'from_name' => 'NUML Timetable']]);
        Http::fake([EmailSender::BREVO_URL => Http::response(['messageId' => 'x'], 201)]);

        (new EmailSender)->send(self::STAFF, 'Subject', 'Body 123456');

        Http::assertSent(fn ($req) => $req->url() === EmailSender::BREVO_URL && $req->header('api-key') === ['key-123']
            && $req['to'] === [['email' => self::STAFF]] && $req['sender']['email'] === 'team@example.com'
            && $req['textContent'] === 'Body 123456');
    }

    public function test_brevo_errors_become_email_errors(): void
    {
        config(['timetable.email' => ['provider' => 'brevo', 'brevo_api_key' => null, 'from' => null, 'from_name' => 'x']]);
        try {
            (new EmailSender)->send(self::STAFF, 's', 't');   // not configured
            $this->fail('no EmailException');
        } catch (EmailException) {
        }

        config(['timetable.email.brevo_api_key' => 'key-123', 'timetable.email.from' => 'team@example.com']);
        Http::fake([EmailSender::BREVO_URL => Http::response(['message' => 'sender not verified'], 400)]);
        $this->expectException(EmailException::class);   // refused by Brevo
        (new EmailSender)->send(self::STAFF, 's', 't');
    }

    public function test_console_provider_only_logs(): void
    {
        config(['timetable.email.provider' => 'console']);
        Http::fake();

        (new EmailSender)->send(self::STAFF, 's', 'code 123456');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ helpers

    private function studentBody(array $changes = []): array
    {
        return [...['email' => self::STUDENT, 'name' => 'Sara Ahmed', 'department_id' => $this->se->id, 'section_id' => $this->sec->id,
            'roll_number' => 'SE-9244330', 'password' => self::PASSWORD], ...$changes];
    }

    private function staffBody(array $changes = []): array
    {
        return [...['email' => self::STAFF, 'name' => 'Ali Khan', 'department_id' => $this->se->id, 'password' => self::PASSWORD], ...$changes];
    }

    private function lastCode(string $to): string
    {
        $mails = array_values(array_filter($this->outbox, fn ($m) => $m['to'] === $to));
        $this->assertNotEmpty($mails, "no email was sent to $to");
        preg_match('/\b(\d{6})\b/', end($mails)['text'], $m);

        return $m[1];
    }

    private function verify(string $email, ?string $code = null): TestResponse
    {
        return $this->postJson('/api/auth/verify-email', ['email' => $email, 'code' => $code ?? $this->lastCode($email)]);
    }

    private function login(string $identifier, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/auth/login', ['identifier' => $identifier, 'password' => $password]);
    }

    /** Moves a row's timestamps into the past, instead of waiting in the test. */
    private function makeOlder(string $model, int $seconds, string $email): void
    {
        $row = $model::where('email', $email)->sole();
        foreach (['sent_at', 'expires_at', 'created_at'] as $col) {
            if ($row->{$col} !== null && in_array($col, $row->getFillable(), true)) {
                $row->{$col} = $row->{$col}->copy()->subSeconds($seconds);
            }
        }
        $row->save();
    }

    /** A signed-up student; returns their token. */
    private function member(): string
    {
        $this->postJson('/api/auth/signup', $this->studentBody());
        $token = $this->verify(self::STUDENT)->json('token');
        $this->outbox = [];

        return $token;
    }
}
