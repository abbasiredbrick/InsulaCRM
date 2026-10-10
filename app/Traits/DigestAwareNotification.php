<?php

namespace App\Traits;

trait DigestAwareNotification
{
    public function via(object $notifiable): array
    {
        return $this->digestChannels($notifiable);
    }

    /**
     * The mail/database channel set honouring the member's delivery preference:
     * a "daily digest" member only gets the database row (the digest mailer
     * picks it up later); everyone else gets mail immediately as well.
     *
     * @return array<int, string>
     */
    protected function digestChannels(object $notifiable): array
    {
        if (($notifiable->notification_delivery ?? 'instant') === 'daily_digest') {
            return ['database'];
        }

        return ['mail', 'database'];
    }
}
