<?php

namespace App\Events\V1;

use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionRefunded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Откладываем бродкаст до коммита текущей БД-транзакции.
     * В Laravel 13 это замена старому интерфейсу ShouldBroadcastAfterCommit.
     */
    public bool $afterCommit = true;

    public function __construct(
        public Transaction $transaction
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.transactions'),
        ];
    }

    public function broadcastWith(): array
    {
        $this->transaction->refresh();

        return [
            'transaction_id' => $this->transaction->id,
            'status'         => $this->transaction->status,
            'success'        => true,
            'reason'         => $this->transaction->meta['refund_reason'] ?? null,
            'updated_at'     => $this->transaction->updated_at?->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'refund.processed';
    }
}
