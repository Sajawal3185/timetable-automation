<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\EmailException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SignupRequest;
use App\Http\Resources\UserResource;
use App\Models\Department;
use App\Models\PendingSignup;
use App\Models\Section;
use App\Models\User;
use App\Rules\MaxBytes;
use App\Rules\NoNulByte;
use App\Services\EmailCodes;
use App\Services\EmailSender;
use App\Services\Security;
use App\Support\Identifiers;
use App\Support\Roles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Self-signup and forgot password (#93's routers/signup.py). All public.
 *
 * There is no university roster, so the NUML inbox is the proof: a 6-digit code
 * is emailed and must be typed back. The role is never sent: a student email
 * (numl-f24-26789@numls.edu.pk) makes a student, any other NUML address a
 * teacher, with no approval step (decided 14 Nov). Only an admin makes anyone
 * HOD or admin, and links a teacher account to its teacher record.
 */
class SignupController extends Controller
{
    public const EMAIL_DOMAIN = '@numls.edu.pk';

    /** numl-<term letter><yy>-<sys id>@numls.edu.pk; any single letter, so spring students aren't pushed onto the staff path. */
    private const STUDENT_EMAIL = '/^numl-([a-z])(\d{2})-(\d+)@numls\.edu\.pk$/D';

    private const EMAIL_SHAPE = '/^[^@\s]+@numls\.edu\.pk$/D';

    private const ALREADY_REGISTERED = 'This email already has an account. Log in instead.';

    private const SIGNUP_EXPIRED = 'This signup has expired. Start again.';

    private const CANT_SEND = "We couldn't send the email right now. Try again in a minute.";

    private const RESET_SENT = 'If that account exists, we sent a code to its email.';

    public function __construct(private EmailSender $mail) {}

    /**
     * Departments and sections for the signup dropdowns.
     *
     * @unauthenticated
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'departments' => Department::orderBy('name')->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code]),
            'sections' => Section::orderBy('name')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'department_id' => $s->department_id]),
        ]);
    }

    /**
     * Starts a signup: a code is emailed. Nothing is an account yet; a new
     * signup replaces an unfinished one for the same email.
     *
     * @unauthenticated
     */
    public function signup(SignupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = $this->numlEmail($data['email']);
        $dept = Department::find($data['department_id']) ?? abort(422, 'Choose your department.');

        $pending = ['email' => $email, 'name' => trim($data['name']), 'department_id' => $dept->id];
        if (preg_match(self::STUDENT_EMAIL, $email, $m)) {
            $section = isset($data['section_id']) ? Section::find($data['section_id']) : null;
            if ($section === null || $section->department_id !== $dept->id) {
                abort(422, 'Choose a section of your department.');
            }
            $roll = Identifiers::normalize($data['roll_number'] ?? '');
            if (! preg_match(Identifiers::ROLL_NUMBER_PATTERN, $roll)) {
                abort(422, 'Roll number should look like se-9244330.');
            }
            $pending += ['kind' => 'student', 'identifier' => explode('@', $email)[0],   // numl-f24-26789
                'section_id' => $section->id, 'roll_number' => $roll, 'sys_id' => $m[3]];
        } else {
            $pending += ['kind' => 'staff', 'identifier' => $email];                     // staff log in with the email
        }

        if ($this->emailTaken($email, $pending['identifier'])) {
            abort(409, self::ALREADY_REGISTERED);
        }
        // Signup sends an email too, so it shares resend-code's one-a-minute
        // limit; otherwise anyone could flood someone's inbox through here.
        if (EmailCodes::sentRecently($email, 'signup')) {
            abort(429, EmailCodes::WAIT_A_MINUTE);
        }

        $pending['password_hash'] = Security::hashPassword($data['password']);
        $pending['created_at'] = EmailCodes::now();
        try {
            $this->sendInTransaction($email, 'signup', function () use ($email, $pending) {
                PendingSignup::where('email', $email)->delete();
                PendingSignup::create($pending);
            });
        } catch (UniqueConstraintViolationException) {   // two signups for one email at the same moment
            abort(409, 'A signup for this email is already in progress.');
        }

        return response()->json(['email' => $email, 'kind' => $pending['kind'], 'expires_in' => EmailCodes::CODE_MINUTES * 60], 202);
    }

    /**
     * The code from the email: the account is created and logged in.
     *
     * @unauthenticated
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:150'], 'code' => ['required', 'string', 'max:20']]);
        $email = $this->numlEmail($data['email']);
        $pending = $this->openSignup($email) ?? abort(400, self::SIGNUP_EXPIRED);
        $code = EmailCodes::check($email, 'signup', $data['code']);   // outside the transaction: see EmailCodes::check
        if ($this->emailTaken($email, $pending->identifier)) {
            abort(409, self::ALREADY_REGISTERED);
        }

        try {
            $user = DB::transaction(function () use ($pending, $code) {
                $user = new User;
                $user->forceFill([
                    'name' => $pending->name, 'identifier' => $pending->identifier, 'email' => $pending->email,
                    'email_verified_at' => EmailCodes::now(), 'password_hash' => $pending->password_hash,
                    'role' => $pending->kind === 'student' ? Roles::STUDENT : Roles::TEACHER,
                    'department_id' => $pending->department_id, 'section_id' => $pending->section_id,
                    'roll_number' => $pending->roll_number, 'sys_id' => $pending->sys_id, 'is_active' => true,
                ])->save();
                $pending->delete();
                $code->delete();   // single use

                return $user->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, self::ALREADY_REGISTERED);
        }

        return $this->loggedIn($user);
    }

    /**
     * A new signup code (at most once a minute).
     *
     * @unauthenticated
     */
    public function resend(Request $request): JsonResponse
    {
        $email = $this->numlEmail($request->validate(['email' => ['required', 'string', 'max:150']])['email']);
        if ($this->openSignup($email) === null) {
            abort(400, self::SIGNUP_EXPIRED);
        }
        if (EmailCodes::sentRecently($email, 'signup')) {
            abort(429, EmailCodes::WAIT_A_MINUTE);
        }
        $this->sendInTransaction($email, 'signup');

        return response()->json([], 202);
    }

    /**
     * Emails a reset code. Always the same answer, whatever happens (even if the
     * email fails), so it can't reveal which accounts exist.
     *
     * @unauthenticated
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:150']]);
        $user = Identifiers::findAccount($data['identifier']);
        if ($user && $user->email && $user->is_active && ! EmailCodes::sentRecently($user->email, 'reset')) {
            try {
                $this->sendInTransaction($user->email, 'reset');
            } catch (ApiException) {
                // already logged; the answer stays the same
            }
        }

        return response()->json(['detail' => self::RESET_SENT], 202);
    }

    /**
     * Sets a new password with the emailed code; every older token stops working.
     *
     * @unauthenticated
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20'],
            'new_password' => ['required', 'string', 'min:8', new MaxBytes, new NoNulByte],
        ]);
        $user = Identifiers::findAccount($data['identifier']);
        if (! $user || ! $user->email || ! $user->is_active) {
            abort(400, EmailCodes::WRONG_CODE);   // same answer as a wrong code: no account probing
        }
        $code = EmailCodes::check($user->email, 'reset', $data['code']);

        DB::transaction(function () use ($user, $code, $data) {
            $user->forceFill([
                'password_hash' => Security::hashPassword($data['new_password']),
                'token_version' => (int) $user->token_version + 1,   // older tokens stop working
            ])->save();
            $code->delete();
        });

        return response()->json(['detail' => 'Password changed. You can log in now.']);
    }

    // ------------------------------------------------------------------ helpers

    private function numlEmail(string $raw): string
    {
        $email = Identifiers::normalize($raw);
        if (! preg_match(self::EMAIL_SHAPE, $email)) {
            abort(400, 'Use your NUML email (ending in '.self::EMAIL_DOMAIN.').');
        }

        return $email;
    }

    private function emailTaken(string $email, string $identifier): bool
    {
        return User::where('email', $email)->orWhere('identifier', $identifier)->orWhere('identifier', $email)->exists();
    }

    /** The unfinished signup for this email, or null if there is none or it expired. */
    private function openSignup(string $email): ?PendingSignup
    {
        $pending = PendingSignup::where('email', $email)->first();
        if ($pending === null || $pending->created_at->lt(EmailCodes::now()->subMinutes(EmailCodes::CODE_MINUTES))) {
            return null;
        }

        return $pending;
    }

    /**
     * New code -> $also (the signup rows) -> email, in one transaction: if the
     * email can't be sent, nothing is saved and the caller gets 503.
     */
    private function sendInTransaction(string $email, string $purpose, ?callable $also = null): void
    {
        try {
            DB::transaction(function () use ($email, $purpose, $also) {
                if ($also) {
                    $also();
                }
                $code = EmailCodes::issue($email, $purpose);
                [$subject, $why] = $purpose === 'signup'
                    ? ['Your NUML Timetable signup code', 'Use this code to finish creating your account']
                    : ['Your NUML Timetable password reset code', 'Use this code to set a new password'];
                $this->mail->send($email, $subject, "$why: $code\n\n"
                    .'It works for '.EmailCodes::CODE_MINUTES." minutes and only once.\n"
                    ."If you didn't ask for this, you can ignore this email.");
            });
        } catch (EmailException $e) {
            Log::error("$purpose code not sent to $email: ".$e->getMessage());
            throw new ApiException(503, self::CANT_SEND);
        }
    }

    /** {token, user}, like login. */
    private function loggedIn(User $user): JsonResponse
    {
        $token = Security::createAccessToken($user->id, $user->role, (int) $user->token_version);

        return response()->json(['token' => $token, 'user' => new UserResource($user)]);
    }
}
