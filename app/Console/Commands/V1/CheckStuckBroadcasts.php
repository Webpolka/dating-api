<?php

namespace App\Console\Commands;

use App\Models\Broadcast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStuckBroadcasts extends Command
{
    protected $signature = 'broadcasts:check-stuck';
    protected $description = 'Находит зависшие рассылки (статус sending > 30 минут) и помечает их как failed';

    public function handle(): void
    {
        $this->info('Проверяем зависшие рассылки...');

        // Ищем рассылки, которые висят в sending больше 30 минут
        $stuckBroadcasts = Broadcast::where('status', Broadcast::STATUS_SENDING)
            ->where('started_at', '<', now()->subMinutes(30))
            ->get();

        if ($stuckBroadcasts->isEmpty()) {
            $this->info('Зависших рассылок не найдено.');
            return;
        }

        foreach ($stuckBroadcasts as $broadcast) {
            $broadcast->markAsFailed();
            Log::error("Broadcast ID {$broadcast->id} was stuck in sending for >30 mins. Marked as failed.", [
                'sent_count' => $broadcast->sent_count,
                'total_recipients' => $broadcast->total_recipients
            ]);
            $this->warn("Рассылка #{$broadcast->id} помечена как failed (зависла).");
        }

        $this->info('Готово.');
    }
}
