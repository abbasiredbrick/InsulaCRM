<?php

namespace App\Notifications;

use App\Models\OpenHouse;
use App\Models\Showing;
use App\Models\Task;
use App\Models\Tenant;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CalendarReminderNotification extends Notification implements ShouldQueue
{
    use DigestAwareNotification;
    use Queueable;

    public function __construct(
        protected Model $record,
        protected Tenant $tenant,
    ) {}

    public function toArray(object $notifiable): array
    {
        $what = $this->recordTitle();

        return [
            'type' => 'calendar_reminder',
            'icon' => 'alarm',
            'color' => 'blue',
            'title' => __('Reminder: :what is coming up', ['what' => $what]),
            'body' => __(':what starts :start. Open your calendar or the CRM to prepare.', [
                'what' => $what,
                'start' => $this->recordStart()->calendarFormat(),
            ]),
            'url' => $this->recordUrl(),
            'record_type' => class_basename($this->record),
            'record_id' => $this->record->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        \App\Helpers\TenantFormatHelper::setTenant($this->tenant);

        $what = $this->recordTitle();

        return (new MailMessage)
            ->subject('['.$this->tenant->name.'] Reminder: '.$what)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You have an upcoming item in your calendar.')
            ->line('**'.$what.'**')
            ->line('**When:** '.$this->recordStart()->calendarFormat())
            ->line($this->recordDetail())
            ->action('View in CRM', $this->recordUrl())
            ->line('Thank you for using '.$this->tenant->name.' CRM.');
    }

    protected function recordTitle(): string
    {
        if ($this->record instanceof Showing) {
            return __('Viewing').': '.($this->record->property?->address ?? __('Unit #:id', ['id' => $this->record->property_id]));
        }

        if ($this->record instanceof OpenHouse) {
            return __('Open house').': '.($this->record->property?->address ?? __('Unit #:id', ['id' => $this->record->property_id]));
        }

        return $this->record->title;
    }

    protected function recordStart(): \Carbon\CarbonInterface
    {
        if ($this->record instanceof Showing || $this->record instanceof OpenHouse) {
            $date = $this->record->showing_date ?? $this->record->event_date;
            $time = $this->record->showing_time ?? $this->record->start_time;

            return $date->copy()->setTimeFrom($this->parseTime($time));
        }

        if ($this->record instanceof Task) {
            return $this->record->due_date->copy()->startOfDay();
        }

        return $this->record->scheduled_at;
    }

    protected function recordDetail(): string
    {
        if ($this->record->lead) {
            return '**Client:** '.$this->record->lead->full_name;
        }

        return '';
    }

    protected function recordUrl(): string
    {
        if ($this->record instanceof Showing) {
            return route('showings.show', $this->record);
        }

        if ($this->record instanceof OpenHouse) {
            return route('open-houses.show', $this->record);
        }

        if ($this->record->lead_id) {
            return route('leads.show', $this->record->lead_id);
        }

        return route('calendar.index');
    }

    protected function parseTime(mixed $value): \Carbon\CarbonInterface
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Throwable) {
            }
        }

        return \Carbon\Carbon::parse('09:00');
    }
}
