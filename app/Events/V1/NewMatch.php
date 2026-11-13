<?php

namespace App\Events;

use App\Models\UserMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewMatch implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public UserMatch $match,
        public int $recipientId
    ) {
        Log::info('🎬 [Event] NewMatch constructed', [
            'match_id' => $this->match->id,
            'recipient_id' => $this->recipientId,
        ]);
    }

    public function broadcastOn(): array
    {
        $channel = 'private-user.' . $this->recipientId;
        Log::info('📡 [Event] NewMatch::broadcastOn()', [
            'match_id' => $this->match->id,
            'channel' => $channel,
        ]);

        return [
            new PrivateChannel('user.' . $this->recipientId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'match.new';
    }

    public function broadcastWith(): array
    {
        $partnerId = $this->match->user1_id === $this->recipientId
            ? $this->match->user2_id
            : $this->match->user1_id;

        $payload = [
            'match_id' => $this->match->id,
            'partner_id' => $partnerId,
            'created_at' => $this->match->created_at?->toIso8601String(),
        ];

        Log::info('📦 [Event] NewMatch::broadcastWith() payload prepared', [
            'match_id' => $this->match->id,
            'payload' => $payload,
        ]);

        return $payload;
    }
}
