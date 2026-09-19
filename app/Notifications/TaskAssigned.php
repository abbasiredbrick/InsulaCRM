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

class TaskAssigned extends Notification implements ShouldQueue
{
    use Queueable;
    use DigestAwareNotification;

    public function __construct(
        protected Task $task,
        protected Tenant $tenant,
        protected ?User $assignedBy = null,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_assigned',
            'icon' => 'checklist',
            'color' => 'blue',
            'title' => __('Task assigned to you'),
            'body' => __(':title (due :due)', [
                'title' => $this->task->title,
                'due' => $this->task->due_label,
            ]),
            'url' => $this->task->lead_id ? url("/leads/{$this->task->lead_id}") : route('dashboard'),
            'task_id' => $this->task->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenantName = $this->tenant->name;

        return (new MailMessage)
            ->subject("[{$tenantName}] Task assigned to you: {$this->task->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line(__('A task has been assigned to you.'))
            ->line("**{$this->task->title}**")
            ->line(__('**Due:** :due', ['due' => $this->task->due_label]))
            ->when($this->assignedBy, fn ($mail) => $mail->line(__('**Assigned by:** :name', ['name' => $this->assignedBy->name])))
            ->line(__('**Client:** :name', ['name' => $this->task->lead?->full_name ?? 'N/A']))
            ->when($this->task->lead_id, fn ($mail) => $mail->action('View Client', url("/leads/{$this->task->lead_id}")))
            ->line('The task has also been added to your calendar.');
    }
}