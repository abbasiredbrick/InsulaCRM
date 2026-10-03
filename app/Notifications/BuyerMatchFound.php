<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\Tenant;
use App\Services\BusinessModeService;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BuyerMatchFound extends Notification implements ShouldQueue
{
    use DigestAwareNotification;
    use Queueable;

    public function __construct(
        protected Deal $deal,
        protected int $matchCount,
        protected float $topScore,
        protected Tenant $tenant,
    ) {}

    public function toArray(object $notifiable): array
    {
        $terms = BusinessModeService::getTerminology($this->tenant);

        return [
            'type' => 'buyer_match_found',
            'icon' => 'users',
            'color' => 'green',
            'title' => __(':label match found', ['label' => $terms['buyer_singular']]),
            'body' => __(':count match(es) for :title (top score: :score)', [
                'count' => $this->matchCount,
                'title' => $this->deal->title,
                'score' => $this->topScore,
            ]),
            'url' => url("/pipeline/{$this->deal->id}"),
            'deal_id' => $this->deal->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deal = $this->deal;
        $terms = BusinessModeService::getTerminology($this->tenant);
        $label = $terms['buyer_singular'];
        $labelPlural = $terms['buyer_label'];
        $leadName = $deal->lead ? "{$deal->lead->first_name} {$deal->lead->last_name}" : 'Unknown';

        return (new MailMessage)
            ->subject("[Keystone] {$label} match found for deal: {$leadName} — {$deal->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$label} matches have been found for a deal.")
            ->line("**Deal:** {$deal->title}")
            ->line("**Number of Matches:** {$this->matchCount}")
            ->line("**Top Match Score:** {$this->topScore}")
            ->action(__('View :label', ['label' => $terms['deal_label']]), url("/pipeline/{$deal->id}"))
            ->line(__('Review the matches and notify interested :label.', ['label' => strtolower($labelPlural)]));
    }
}
