<?php

namespace App\Console\Commands\V1;

use App\Jobs\V1\Admin\System\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\AdminLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendScheduledBroadcasts extends Command
{
    protected $signature = 'broadcasts:send-scheduled';
    protected $description = 'Отправляет запланированные оповещения (рассылки)';

    public function handle(): int
    {
        // dueForDispatch использует индекс ['status', 'scheduled_at'], запрос летает за 1ms
        $broadcasts = Broadcast::dueForDispatch()->get();

        if ($broadcasts->isEmpty()) {
            $this->info('Нет запланированных оповещений для отправки.');
            return Command::SUCCESS;
        }

        foreach ($broadcasts as $broadcast) {
            try {
                $before = $broadcast->only(['status', 'started_at']);

                $updated = Broadcast::where('id', $broadcast->id)
                    ->where('status', 'scheduled')
                    ->update([
                        'status' => 'sending',
                        'started_at' => now()
                    ]);

                if ($updated) {
                    // ФИКС: Убрали $broadcast->refresh(). Просто вручную синхронизируем память.
                    $broadcast->status = 'sending';
                    $broadcast->started_at = now();
                    $after = $broadcast->only(['status', 'started_at']);

                    // Пишем в Журнал (null вместо админа, так как это система)
                    AdminLog::record('broadcast.send_scheduled', $broadcast, null, $before, $after);
                    Log::info("Крон запустил рассылку по расписанию", ['broadcast_id' => $broadcast->id]);

                    // Отправляем в очередь
                    SendBroadcastJob::dispatch($broadcast->id, $broadcast->target_audience)->onQueue('broadcasts');

                    $this->info("Оповещение #{$broadcast->id} передано в очередь на отправку.");
                } else {
                    $this->warn("Оповещение #{$broadcast->id} уже запущено или изменено, пропуск.");
                }
            } catch (\Exception $e) {
                $this->error("Критическая ошибка при запуске #{$broadcast->id}: " . $e->getMessage());
                Log::error("Сбой отправки оповещения #{$broadcast->id}: " . $e->getMessage());
                $broadcast->markAsFailed();
            }
        }

        return Command::SUCCESS;
    }
}
