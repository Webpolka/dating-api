<?php

namespace App\Actions\Admin\Users;

use App\Models\AdminLog;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\DB;

class ManageUserBlocksAction
{
    /**
     * Принудительно снять блокировку админом.
     */
    public function unblock(UserBlock $block, User $admin): void
    {
        // ФИКС: Сохраняем все нужные данные (включая ID) ДО транзакции
        $blockId = $block->id;
        $blockerId = $block->blocker_id;
        $blockedId = $block->blocked_id;
        $reason = $block->reason;

        // ФИКС: Обернули в транзакцию, чтобы лог и удаление прошли атомарно
        DB::transaction(function () use ($block, $admin, $blockId, $blockerId, $blockedId, $reason) {

            $block->delete();

            $after = [
                'status' => 'unblocked',
                'unblocked_by' => $admin->id,
                'context' => [
                    'block_id' => $blockId,
                    'blocker_id' => $blockerId,
                    'blocked_id' => $blockedId,
                    'original_reason' => $reason,
                    'admin_id' => $admin->id
                ]
            ];

            $participants = array_filter([$blockerId, $blockedId]);

            AdminLog::record('user_block.delete', $block, $admin, null, $after, participants: $participants);
        });
    }
}
