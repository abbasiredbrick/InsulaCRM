@props([
    'name' => 'reminder_minutes',
    'selected' => null,
    'label' => 'Reminder',
])

@php
    $options = [
        null => __('None'),
        15 => __('15 minutes'),
        30 => __('30 minutes'),
        60 => __('1 hour'),
        120 => __('2 hours'),
        1440 => __('1 day'),
        2880 => __('2 days'),
        10080 => __('1 week'),
    ];
    $current = $selected ?? auth()->user()->tenant->calendar_reminder_default_minutes;
@endphp

<div class="mb-0">
    <label class="form-label">{{ __($label) }}</label>
    <select name="{{ $name }}" class="form-select">
        @foreach($options as $value => $text)
            <option value="{{ $value ?? '' }}" @selected((string) ($value ?? '') === (string) ($current ?? ''))>{{ $text }}</option>
        @endforeach
    </select>
    <small class="form-hint">{{ __('In-app and email reminder before the event.') }}</small>
</div>