@php
    $user = auth()->user();
    $calendarConnections = $user->cloudConnections()->where('scope', 'calendar')->get();
    $googleConfigured = $user->tenant->cloudProviderConfigured('google');
    $microsoftConfigured = $user->tenant->cloudProviderConfigured('microsoft');
    $connected = $calendarConnections->isNotEmpty();
    $icalToken = $user->calendar_feed_token;
    $hasOption = $connected || $googleConfigured || $microsoftConfigured || ! blank($icalToken);
@endphp

@if($hasOption)
<div class="card border-0 shadow-sm mb-3" id="calendar-connect-card">
    <div class="card-body">
        @if($connected)
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="avatar avatar-sm bg-success-lt text-success"><i class="bi bi-calendar-check"></i></span>
                    <div>
                        <div class="fw-bold">{{ __('Calendar sync is on') }}</div>
                        <div class="text-secondary small">
                            {{ __('Connected as') }}
                            @foreach($calendarConnections as $conn)
                                <strong>{{ $conn->provider_account_email ?: ucfirst($conn->provider) }}</strong>{{ $loop->last ? '' : ', ' }}
                            @endforeach
                        </div>
                    </div>
                </div>
                <a href="{{ route('my-cloud.show') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-gear"></i> {{ __('Manage') }}
                </a>
            </div>
        @else
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="avatar avatar-sm bg-primary-lt text-primary"><i class="bi bi-calendar-plus"></i></span>
                    <div>
                        <div class="fw-bold">{{ __('Sync your calendar') }}</div>
                        <div class="text-secondary small">{{ __('Sign in with your Google or Microsoft account to sync your viewings, tasks and meetings to your own calendar.') }}</div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if($googleConfigured)
                        <a href="{{ route('cloud.start', ['provider' => 'google', 'scope' => 'calendar', 'redirect' => request()->getRequestUri()]) }}" class="btn btn-primary btn-sm">
                            <i class="bi bi-google"></i> {{ __('Connect Google Calendar') }}
                        </a>
                    @endif
                    @if($microsoftConfigured)
                        <a href="{{ route('cloud.start', ['provider' => 'microsoft', 'scope' => 'calendar', 'redirect' => request()->getRequestUri()]) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-microsoft"></i> {{ __('Connect Microsoft Calendar') }}
                        </a>
                    @endif
                    @if($icalToken)
                        <a href="{{ route('calendar.feed', ['token' => $icalToken]) }}" target="_blank" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-file-earmark-ruled"></i> {{ __('My iCal feed') }}
                        </a>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endif
