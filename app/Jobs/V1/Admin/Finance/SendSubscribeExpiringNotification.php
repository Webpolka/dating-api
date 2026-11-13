<?php

namespace App\Jobs;

use App\Models\UserSubscription;
use App\Notifications\SubscriptionExpiringSoonNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable; 
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSubscribeExpiringNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels; // <--- И СЮДА

    public $tries = 3;

    public function __construct(
        protected UserSubscription $subscription
    ) {}

    public function handle(): void
    {
        // ЗАЩИТА ОТ ГОНКИ
        if (!$this->subscription->isActive() || !$this->subscription->ends_at->between(now(), now()->addHours(25))) {
            Log::info("SendSubscribeExpiringNotification: Подписка ID {$this->subscription->id} больше не требует уведомления.");
            return;
        }

        $user = $this->subscription->user;

        if (!$user) {
            return; 
        }

         $user->notify(new SubscriptionExpiringSoonNotification(
            subscriptionId: $this->subscription->id,
            planName: $this->subscription->plan?->name ?? 'Подписка',
            price: $this->subscription->plan ? number_format($this->subscription->plan->price, 2) . ' ' . $this->subscription->plan->currency : null,
            endsAt: $this->subscription->ends_at->format('d.m.Y H:i'),
            isAutoRenew: (bool) $this->subscription->is_auto_renew
        ));
        
        Log::info("SendSubscribeExpiringNotification: Отправлено юзеру ID {$user->id} о подписке ID {$this->subscription->id}");
    }
}