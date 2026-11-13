<?php

namespace Tests\Feature\Admin;

use App\Models\AdminLog;
use App\Notifications\UserBanned;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Критический флоу: бан юзера из админки.
 *
 * Цепочка: POST /api/admin/users/{userId}/ban
 *   → UserController::ban (thin)
 *   → BanUserRequest (валидация type/reason/days)
 *   → ToggleUserBanAction::execute (статус, AdminLog, уведомление)
 *
 * Требования к окружению: тестовая БД (DB_DATABASE=testing),
 * PostGIS-совместимый сервер (миграция 0001 создаёт расширение).
 */
class UserBanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_guest_cannot_ban_users(): void
    {
        $victim = User::factory()->create();

        $this->postJson("/api/admin/users/{$victim->id}/ban", [
            'type' => 'permanent',
            'reason' => 'spam',
        ])->assertStatus(401);

        $this->assertSame(User::STATUS_ACTIVE, $victim->fresh()->status);
    }

    public function test_regular_user_cannot_ban_users(): void
    {
        $actor = User::factory()->create(); // role=user
        $victim = User::factory()->create();

        $this->actingAs($actor, 'web')
            ->postJson("/api/admin/users/{$victim->id}/ban", [
                'type' => 'permanent',
                'reason' => 'spam',
            ])->assertStatus(403);

        $this->assertSame(User::STATUS_ACTIVE, $victim->fresh()->status);
    }

    public function test_admin_can_ban_user_permanently(): void
    {
        Notification::fake();
        $victim = User::factory()->create();

        $response = $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$victim->id}/ban", [
                'type' => 'permanent',
                'reason' => 'spam',
            ]);

        $response->assertOk();

        $fresh = $victim->fresh();
        $this->assertSame(User::STATUS_BANNED, $fresh->status);
        $this->assertNull($fresh->banned_until); // permanent = без срока
        $this->assertNotNull($fresh->ban_reason);

        // Журнал аудита: user.ban
        $this->assertDatabaseHas('admin_logs', [
            'action' => 'user.ban',
            'loggable_type' => User::class,
            'loggable_id' => $victim->id,
        ]);

        // Уведомление о бане ушло жертве
        Notification::assertSentTo($victim, UserBanned::class);
    }

    public function test_temp_ban_sets_expiry_date(): void
    {
        $victim = User::factory()->create();

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$victim->id}/ban", [
                'type' => 'temp',
                'reason' => 'insult',
                'days' => 7,
            ])->assertOk();

        $fresh = $victim->fresh();
        $this->assertSame(User::STATUS_BANNED, $fresh->status);
        $this->assertNotNull($fresh->banned_until);
    }

    public function test_shadow_ban_sets_shadowbanned_status(): void
    {
        $victim = User::factory()->create();

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$victim->id}/ban", [
                'type' => 'shadow',
                'reason' => 'multi_account',
            ])->assertOk();

        $this->assertSame(User::STATUS_SHADOWBANNED, $victim->fresh()->status);
        $this->assertDatabaseHas('admin_logs', [
            'action' => 'user.shadowban',
            'loggable_id' => $victim->id,
        ]);
    }

    public function test_reban_with_same_type_is_idempotent(): void
    {
        $victim = User::factory()->banned()->create();

        $response = $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$victim->id}/ban", [
                'type' => 'permanent',
                'reason' => 'spam',
            ]);

        $response->assertOk()
            ->assertJson(['message' => 'Пользователь уже заблокирован этим типом бана']);

        // Второй бан не создаёт лишнюю запись в журнале
        $this->assertSame(
            0,
            AdminLog::where('action', 'user.ban')->where('loggable_id', $victim->id)->count()
        );
    }

    public function test_invalid_ban_reason_is_rejected(): void
    {
        $victim = User::factory()->create();

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$victim->id}/ban", [
                'type' => 'permanent',
                'reason' => 'totally_made_up_reason',
            ])->assertStatus(422);

        $this->assertSame(User::STATUS_ACTIVE, $victim->fresh()->status);
    }
}
