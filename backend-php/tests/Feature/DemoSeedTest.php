<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\SwapRequest;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Services\DemoSeed;
use App\Services\Security;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/** Port of backend/tests/test_seed.py, plus the demo flows the seed exists for. */
class DemoSeedTest extends TestCase
{
    private const PASSWORD = 'demo-password-1';

    public function test_seed_creates_accounts_the_demo_can_use(): void
    {
        DemoSeed::run(self::PASSWORD);

        $hod = $this->user('hod-100301');
        $this->assertSame('hod', $hod->role);
        $this->assertNotNull($hod->department_id);                         // can approve its department's swaps
        $teacher = $this->user('tr-100201');
        $linked = Teacher::where('user_id', $teacher->id)->sole();
        $this->assertSame($hod->department_id, $linked->department_id);    // teacher login -> Teacher row, same department
        $this->assertNotNull($this->user('se-9244330')->section_id);       // student sees a section's timetable
        $this->assertTrue(Security::verifyPassword(self::PASSWORD, $teacher->password_hash));
        $this->assertNull($this->user('se-9244331')->password_hash);       // still to be activated
        $this->assertSame(count(DemoSeed::TIMETABLE), TimetableEntry::count());
    }

    public function test_seed_twice_adds_nothing(): void
    {
        DemoSeed::run(self::PASSWORD);
        $before = [User::count(), Teacher::count(), TimetableEntry::count()];

        $again = DemoSeed::run(self::PASSWORD);

        $this->assertSame([0, 0], [$again['added'], $again['timetable_entries']]);
        $this->assertSame($before, [User::count(), Teacher::count(), TimetableEntry::count()]);
    }

    public function test_reset_puts_the_demo_back(): void
    {
        DemoSeed::run(self::PASSWORD);

        // What one demo run leaves behind: an approved swap moved two classes, a
        // notification was sent, and the roster student chose a password.
        [$a, $b] = TimetableEntry::orderBy('id')->take(2)->get()->all();
        [$aTimes, $bTimes] = [$a->only(['start_time', 'end_time']), $b->only(['start_time', 'end_time'])];
        $a->update($bTimes);
        $b->update($aTimes);
        SwapRequest::create(['requester_teacher_id' => $a->teacher_id, 'entry_id' => $a->id, 'target_entry_id' => $b->id, 'status' => 'approved']);
        Notification::create(['user_id' => Teacher::find($a->teacher_id)->user_id, 'type' => 'swap_approved', 'message' => 'x']);
        $this->user('se-9244331')->forceFill(['password_hash' => 'claimed'])->save();

        $done = DemoSeed::run(self::PASSWORD, reset: true);

        $this->assertSame([1, 1], [$done['swaps_deleted'], $done['notifications_deleted']]);
        $this->assertSame([0, 0], [SwapRequest::count(), Notification::count()]);
        $this->assertNull($this->user('se-9244331')->password_hash);
        $slots = TimetableEntry::get()->map(fn ($e) => [$e->day_of_week, substr($e->start_time, 0, 5), substr($e->end_time, 0, 5)])->all();
        $expected = array_map(fn ($row) => array_slice($row, 4), DemoSeed::TIMETABLE);
        $this->assertEqualsCanonicalizing($expected, $slots);
        $this->assertSame(count(DemoSeed::TIMETABLE), TimetableEntry::count());
    }

    public function test_reset_also_restores_changed_passwords(): void
    {
        DemoSeed::run(self::PASSWORD);
        $this->user('tr-100201')->forceFill(['password_hash' => Security::hashPassword('someone-elses')])->save();

        DemoSeed::run(self::PASSWORD);   // a plain run leaves passwords alone
        $this->assertFalse(Security::verifyPassword(self::PASSWORD, $this->user('tr-100201')->password_hash));

        DemoSeed::run(self::PASSWORD, reset: true);
        $this->assertTrue(Security::verifyPassword(self::PASSWORD, $this->user('tr-100201')->password_hash));
    }

    public function test_short_password_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DemoSeed::run('short');
    }

    // ------------------------------------------------------------ the demo flows

    public function test_seeded_accounts_can_log_in(): void
    {
        DemoSeed::run(self::PASSWORD);

        foreach (['adm-100001' => 'admin', 'hod-100301' => 'hod', 'tr-100201' => 'teacher', 'se-9244330' => 'student'] as $id => $role) {
            $this->postJson('/api/auth/login', ['identifier' => $id, 'password' => self::PASSWORD])->assertOk()
                ->assertJsonPath('user.role', $role);
        }
        // The roster student has no password yet: activation, not login.
        $this->postJson('/api/auth/login', ['identifier' => 'se-9244331', 'password' => self::PASSWORD])->assertUnauthorized();
    }

    public function test_the_two_demo_swaps_behave_as_the_script_says(): void
    {
        DemoSeed::run(self::PASSWORD);
        $entry = fn (string $section, string $day, string $start) => TimetableEntry::whereHas('section', fn ($q) => $q->where('name', $section))
            ->where('day_of_week', $day)->where('start_time', "$start:00")->sole()->id;
        $this->asUser($this->user('tr-100201'));   // Dr. Ahmed Raza owns SE-311

        // clash: Ms. Sara Khan already teaches 5B on Monday 08:30
        $this->postJson('/api/swap-requests', ['entry_id' => $entry('BSSE-5A', 'Monday', '08:30'), 'target_entry_id' => $entry('BSSE-5A', 'Monday', '10:00')])
            ->assertStatus(409)->assertJsonPath('detail.message', 'Swap would cause scheduling conflicts');
        // free
        $sid = $this->postJson('/api/swap-requests', ['entry_id' => $entry('BSSE-5A', 'Wednesday', '10:00'), 'target_entry_id' => $entry('BSSE-5B', 'Wednesday', '08:30')])
            ->assertCreated()->json('id');
        $this->asUser($this->user('hod-100301'));
        $this->postJson("/api/swap-requests/$sid/approve")->assertOk()->assertJsonPath('status', 'approved');
    }

    // ------------------------------------------------------------ the commands

    public function test_demo_seed_command(): void
    {
        config(['timetable.seed_password' => self::PASSWORD]);

        $this->artisan('demo:seed')->expectsOutputToContain('timetable entries loaded')
            ->expectsOutputToContain('hod-100301')->assertExitCode(0);
        $this->artisan('demo:seed --reset')->expectsOutputToContain('Reset removed 0 swap requests')->assertExitCode(0);
        $this->assertSame(count(DemoSeed::TIMETABLE), TimetableEntry::count());
    }

    public function test_db_seed_uses_the_same_data(): void
    {
        config(['timetable.seed_password' => self::PASSWORD]);

        $this->artisan('db:seed')->assertExitCode(0);
        $this->assertSame(count(DemoSeed::USERS), User::count());
    }

    public function test_command_refuses_a_missing_password(): void
    {
        config(['timetable.seed_password' => null]);

        $this->artisan('demo:seed')->expectsOutputToContain('Set SEED_PASSWORD')->assertExitCode(2);
        $this->assertSame(0, User::count());
    }

    public function test_command_refuses_fastapis_database(): void
    {
        config(['timetable.seed_password' => self::PASSWORD]);
        Schema::create('alembic_version', fn ($t) => $t->string('version_num'));

        $this->artisan('demo:seed --reset')->expectsOutputToContain("FastAPI's database")->assertExitCode(1);
        $this->assertSame(0, DB::table('users')->count());
    }

    private function user(string $identifier): User
    {
        return User::where('identifier', $identifier)->sole();
    }
}
