<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use DigestAwareNotification;

    public function __construct(
        protected Task $task,
        protected Tenant $tenant,
        protected string $summary,
        protected ?User $actor = null,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_activity',
            'icon' => 'activity',
            'color' => 'purple',
            'title' => __('Task update'),
            'body' => $this->summary,
            'url' => $this->task->lead_id ? url("/leads/{$this->task->lead_id}") : route('dashboard'),
            'task_id' => $this->task->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenantName = $this->tenant->name;

        return (new MailMessage)
            ->subject("[{$tenantName}] Task update: {$this->task->title}")
            ->greeting("Hello {$notifiable->name},")
            ->when($this->actor, fn ($mail) => $mail->line(__(':name updated "**:title**"', ['name' => $this->actor->name, 'title' => $this->task->title])))
            ->line($this->summary)
            ->when($this->task->lead_id, fn ($mail) => $mail->action('View Client', url("/leads/{$this->task->lead_id}")))
            ->line(__('Due: :due', ['due' => $this->task->due_label]));
    }
}