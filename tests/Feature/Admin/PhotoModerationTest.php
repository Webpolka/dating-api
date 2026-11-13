<?php

namespace Tests\Feature\Admin;

use App\Models\AdminLog;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\PhotoModerated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ProcessApprovedPhoto;
use Tests\TestCase;

/**
 * Критический флоу: модерация фото.
 *
 * Цепочка: POST /api/admin/photos/{id}/approve|reject
 *   → PhotoController (thin)
 *   → RejectPhotoRequest (только "пользовательские" причины)
 *   → ModeratePhotoAction (статус, AdminLog, уведомление, job обработки)
 */
class PhotoModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moderator = User::factory()->moderator()->create();
    }

    public function test_guest_cannot_moderate_photos(): void
    {
        $photo = Photo::factory()->create();

        $this->postJson("/api/admin/photos/{$photo->id}/reject", [
            'reason' => 'porn',
        ])->assertStatus(401);

        $this->assertSame(Photo::STATUS_PENDING, $photo->fresh()->status);
    }

    public function test_support_role_cannot_moderate_photos(): void
    {
        $support = User::factory()->support()->create();
        $photo = Photo::factory()->create();

        $this->actingAs($support, 'web')
            ->postJson("/api/admin/photos/{$photo->id}/reject", [
                'reason' => 'porn',
            ])->assertStatus(403);

        $this->assertSame(Photo::STATUS_PENDING, $photo->fresh()->status);
    }

    public function test_moderator_can_reject_photo(): void
    {
        Notification::fake();
        $photo = Photo::factory()->create(); // owner создаётся фабрикой

        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/photos/{$photo->id}/reject", [
                'reason' => 'porn',
            ])
            ->assertOk()
            ->assertJson(['message' => 'Фото отклонено']);

        $fresh = $photo->fresh();
        $this->assertSame(Photo::STATUS_REJECTED, $fresh->status);
        $this->assertSame('porn', $fresh->reject_reason);

        $this->assertDatabaseHas('admin_logs', [
            'action' => 'photo.reject',
            'loggable_type' => Photo::class,
            'loggable_id' => $photo->id,
        ]);

        Notification::assertSentTo($photo->user, PhotoModerated::class);
    }

    public function test_moderator_can_approve_photo_and_dispatches_processing(): void
    {
        Queue::fake();
        Notification::fake();
        $photo = Photo::factory()->create();

        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/photos/{$photo->id}/approve")
            ->assertOk()
            ->assertJson(['message' => 'Фото одобрено. Запущена обработка...']);

        $this->assertSame(Photo::STATUS_APPROVED, $photo->fresh()->status);

        // Тяжёлая обработка (нарезка, pHash) уезжает в очередь
        Queue::assertPushed(ProcessApprovedPhoto::class);
    }

    public function test_system_reject_reasons_are_not_allowed_via_api(): void
    {
        $photo = Photo::factory()->create();

        // file_missing — системная причина, доступна только job'е обработки
        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/photos/{$photo->id}/reject", [
                'reason' => 'file_missing',
            ])->assertStatus(422);

        $this->assertSame(Photo::STATUS_PENDING, $photo->fresh()->status);
    }

    public function test_moderating_missing_photo_returns_404(): void
    {
        $this->actingAs($this->moderator, 'web')
            ->postJson('/api/admin/photos/999999/reject', [
                'reason' => 'porn',
            ])->assertStatus(404);
    }
}
