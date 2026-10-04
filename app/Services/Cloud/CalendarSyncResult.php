<?php

namespace App\Services\Cloud;

/**
 * Outcome of a single CloudCalendarService::sync() pass.
 *
 * Calendar sync is best effort and must never fail the business request, but it
 * used to fail completely silently: an unusable OAuth token or a provider outage
 * left the record saved with no external event and nobody able to tell. Every
 * call site now receives this so it can warn the agent instead.
 */
class CalendarSyncResult
{
    /** @var list<int> User ids whose external event is present and current. */
    public array $synced = [];

    /** @var list<array{user_id:int,name:string,error:string}> */
    public array $failed = [];

    /** @var list<int> Involved users with no calendar connection at all. */
    public array $unconnected = [];

    /** The tenant has external calendar sync switched off. */
    public bool $disabled = false;

    /** The record was cancelled/completed, so its events were withdrawn. */
    public bool $removed = false;

    public static function disabled(): self
    {
        $result = new self;
        $result->disabled = true;

        return $result;
    }

    public static function removed(): self
    {
        $result = new self;
        $result->removed = true;

        return $result;
    }

    public function recordSuccess(int $userId): void
    {
        if (! in_array($userId, $this->synced, true)) {
            $this->synced[] = $userId;
        }
    }

    public function recordFailure(int $userId, string $name, string $error): void
    {
        $this->failed[] = ['user_id' => $userId, 'name' => $name, 'error' => $error];
    }

    public function recordUnconnected(int $userId): void
    {
        if (! in_array($userId, $this->unconnected, true)) {
            $this->unconnected[] = $userId;
        }
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    /**
     * True when a target that should have received an event did not. A user with
     * no connection is not a failure — the system calendar and in-app reminders
     * still cover them — but a user we could not write to is.
     */
    public function isComplete(): bool
    {
        return ! $this->hasFailures();
    }

    /**
     * Warning to show the agent, or null when every target is in sync.
     */
    public function failureMessage(): ?string
    {
        if (! $this->hasFailures()) {
            return null;
        }

        $names = collect($this->failed)
            ->pluck('name')
            ->filter()
            ->unique()
            ->implode(', ');

        return __('Calendar entry could not be created for :name. They need to reconnect their calendar in My Cloud.', [
            'name' => $names,
        ]);
    }
}
