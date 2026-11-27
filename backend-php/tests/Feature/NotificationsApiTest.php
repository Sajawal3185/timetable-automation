<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

/**
 * Port of backend/tests/test_notifications.py (list, unread count, mark read,
 * privacy). That a swap saves notifications is tested in SwapsApiTest, and
 * that a makeup booking does in MakeupApiTest.
 */
class NotificationsApiTest extends TestCase
{
    private User $teacher;

    private User $teacher2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->role('teacher')->create();
        $this->teacher2 = User::factory()->role('teacher')->create();
    }

    public function test_unread_count_only_counts_my_own_notifications(): void
    {
        $this->addUnread($this->teacher, 2);
        $this->addUnread($this->teacher2, 1);

        $this->assertSame(2, $this->unreadFor($this->teacher));
        $this->assertSame(1, $this->unreadFor($this->teacher2));
        $this->assertSame(0, $this->unreadFor(User::factory()->role('student')->create()));
    }

    public function test_mark_one_notification_read(): void
    {
        [$first] = $this->addUnread($this->teacher, 2);

        $this->asUser($this->teacher)->patchJson("/api/notifications/$first/read")->assertOk()
            ->assertJsonPath('id', $first)->assertJsonPath('is_read', true);
        $this->assertTrue(Notification::find($first)->is_read);
        $this->assertSame(1, $this->unreadFor($this->teacher));
    }

    public function test_mark_all_read(): void
    {
        $this->addUnread($this->teacher, 3);
        [$other] = $this->addUnread($this->teacher2, 1);

        $this->asUser($this->teacher)->postJson('/api/notifications/read-all')->assertOk()->assertExactJson(['updated' => 3]);
        $this->assertSame(0, $this->unreadFor($this->teacher));
        $this->assertFalse(Notification::find($other)->is_read);   // someone else's: untouched
        $this->asUser($this->teacher)->postJson('/api/notifications/read-all')->assertExactJson(['updated' => 0]);
    }

    public function test_cannot_mark_another_users_notification_read(): void
    {
        [$theirs] = $this->addUnread($this->teacher2, 1);

        $this->asUser($this->teacher)->patchJson("/api/notifications/$theirs/read")->assertNotFound()
            ->assertExactJson(['detail' => 'Notification not found']);
        $this->assertFalse(Notification::find($theirs)->is_read);
    }

    public function test_unknown_notification_id_gives_404(): void
    {
        $this->asUser($this->teacher)->patchJson('/api/notifications/99999/read')->assertNotFound();
    }

    public function test_list_is_mine_newest_first_with_filters(): void
    {
        [$old, $mid, $new] = $this->addUnread($this->teacher, 3);
        $this->addUnread($this->teacher2, 2);
        Notification::find($mid)->update(['is_read' => true]);
        $this->asUser($this->teacher);

        $this->assertSame([$new, $mid, $old], array_column($this->getJson('/api/notifications')->assertOk()->json(), 'id'));
        $this->assertSame([$new, $old], array_column($this->getJson('/api/notifications?unread_only=true')->json(), 'id'));
        $this->assertSame([$new], array_column($this->getJson('/api/notifications?limit=1')->json(), 'id'));
        $this->getJson('/api/notifications?limit=0')->assertStatus(422);
        $this->getJson('/api/notifications?unread_only=maybe')->assertStatus(422);

        $this->assertSame(['id', 'type', 'message', 'related_id', 'is_read', 'created_at'], array_keys($this->getJson('/api/notifications')->json(0)));
        $this->assertSame(['swap_requested', 'Note 3', 7, false], [
            $this->getJson('/api/notifications')->json('0.type'), $this->getJson('/api/notifications')->json('0.message'),
            $this->getJson('/api/notifications')->json('0.related_id'), $this->getJson('/api/notifications')->json('0.is_read'),
        ]);
    }

    public function test_every_endpoint_needs_a_login(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->patchJson('/api/notifications/1/read')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
    }

    /** @return list<int> the new notifications' ids, oldest first */
    private function addUnread(User $user, int $n): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = Notification::forceCreate([
                'user_id' => $user->id, 'type' => 'swap_requested', 'message' => "Note $i", 'related_id' => 7,
                'created_at' => now()->subMinutes($n - $i),
            ])->id;
        }

        return $ids;
    }

    private function unreadFor(User $user): int
    {
        return $this->asUser($user)->getJson('/api/notifications/unread-count')->assertOk()->json('count');
    }
}
