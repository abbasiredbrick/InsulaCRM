<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * In-app notification when a team member sends a chat message (or @mentions the
 * recipient), so they can click straight through and reply.
 *
 * @mentions also email the recipient (unless they opted into the daily digest),
 * matching the lead-assignment delivery convention. Deliberately synchronous (no
 * ShouldQueue): the production server has no queue worker, so the database
 * channel must persist immediately.
 */
class NewChatMessage extends Notification
{
    public function __construct(
        protected Conversation $conversation,
        protected Message $message,
        protected User $author,
        protected bool $isMention = false,
    ) {}

    public function via(object $notifiable): array
    {
        if (! $this->isMention) {
            return ['database'];
        }

        if (($notifiable->notification_delivery ?? 'instant') === 'daily_digest') {
            return ['database'];
        }

        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leadName = $this->conversation->lead?->full_name;

        return (new MailMessage)
            ->subject(__('[Keystone] :author mentioned you in chat', ['author' => $this->author->name])
                .($leadName ? __(' · :lead', ['lead' => $leadName]) : ''))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':author mentioned you in a team chat message:', ['author' => $this->author->name]))
            ->line('> '.$this->message->preview())
            ->action(__('Open Chat'), url('/chat/'.$this->conversation->id))
            ->line(__('Reply in Keystone to continue the conversation.'));
    }

    public function toArray(object $notifiable): array
    {
        $conversationUrl = url('/chat/'.$this->conversation->id);

        if ($this->isMention) {
            $title = __(':name mentioned you in chat', ['name' => $this->author->name]);
        } else {
            $title = __('New message from :name', ['name' => $this->author->name]);
        }

        if ($this->conversation->isLeadLinked() && $this->conversation->lead) {
            $title .= ' · '.$this->conversation->lead->full_name;
        }

        return [
            'type' => 'chat',
            'icon' => 'messages',
            'color' => 'blue',
            'title' => $title,
            'body' => $this->message->preview(),
            'url' => $conversationUrl,
            'conversation_id' => $this->conversation->id,
            'lead_id' => $this->conversation->lead_id,
            'is_mention' => $this->isMention,
        ];
    }
}
