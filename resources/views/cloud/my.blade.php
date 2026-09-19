@extends('layouts.app')

@section('title', __('My Cloud'))
@section('page-title', __('My Cloud'))

@section('breadcrumbs')
<li class="breadcrumb-item active" aria-current="page">{{ __('My Cloud') }}</li>
@endsection

@section('content')
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">{{ __('Calendar connections') }}</h5>
                <p class="text-muted">{{ __('Connect your Google or Microsoft calendar so scheduled viewings, follow-ups, reminders and meetings appear on your phone. The CRM system calendar always holds your schedules, and reminders are sent in-app and by email when an event is not synced to a connected calendar.') }}</p>

                @php
                    $calendarConnected = auth()->user()->hasCalendarConnection();
                @endphp
                @if(! $calendarConnected)
                    <div class="alert alert-info py-2">
                        <i class="bi bi-calendar-x"></i>
                        {!! __('<strong>No calendar connected:</strong> your schedules stay on the CRM system calendar and reminders are delivered in-app and by email. Connect a calendar below (or enable the iCal feed) to also sync them to Google/Microsoft.') !!}
                    </div>
                @else
                    <div class="alert alert-success py-2">
                        <i class="bi bi-calendar-check"></i> {{ __('A calendar is connected. Scheduled viewings, reminders and meetings sync to it.') }}
                    </div>
                @endif

                <div class="row">
                    @foreach(['google', 'microsoft'] as $provider)
                        @php
                            $configured = $provider === 'google' ? ($googleConfigured ?? false) : ($microsoftConfigured ?? false);
                            $conn = ($connections['calendar'] ?? collect())->where('provider', $provider)->first();
                            $name = $provider === 'google' ? 'Google' : 'Microsoft';
                            $icon = $provider === 'google' ? 'bi-google' : 'bi-microsoft';
                        @endphp
                        <div class="col-md-6">
                            <div class="card h-100 mb-2">
                                <div class="card-body">
                                    <h6 class="card-title"><i class="bi {{ $icon }}"></i> {{ $name }}</h6>
                                    @if($conn)
                                        <p class="mb-1 text-success"><i class="bi bi-patch-check"></i> {{ __('Connected as') }} <strong>{{ $conn->provider_account_email ?: ucfirst($provider) }}</strong></p>
                                        <form method="POST" action="{{ route('cloud.disconnect', ['provider' => $provider]) }}" data-provider="{{ $provider }}" class="d-inline disconnect-form">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="scope" value="calendar">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Disconnect') }}</button>
                                        </form>
                                    @elseif($configured)
                                        <a href="{{ route('cloud.start', ['provider' => $provider, 'scope' => 'calendar']) }}" class="btn btn-sm btn-primary">
                                            <i class="bi bi-plug"></i> {{ __('Connect :name Calendar', ['name' => $name]) }}
                                        </a>
                                    @else
                                        <p class="text-muted mb-1">{{ __('Tenant admin must configure') }} {{ $name }} {{ __('credentials in Settings') }}.</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <hr>

                <h5 class="card-title mt-2">{{ __('Drive / photo storage') }}</h5>
                <p class="text-muted">{{ __('Inventory photos you upload are saved per agent. Choose where your photos are stored.') }}</p>

                <div class="row">
                    @foreach(['google', 'microsoft'] as $provider)
                        @php
                            $configured = $provider === 'google' ? ($googleConfigured ?? false) : ($microsoftConfigured ?? false);
                            $conn = ($connections['drive'] ?? collect())->where('provider', $provider)->first();
                            $name = $provider === 'google' ? 'Google Drive' : 'OneDrive';
                            $icon = $provider === 'google' ? 'bi-google' : 'bi-microsoft';
                        @endphp
                        <div class="col-md-6">
                            <div class="card h-100 mb-2">
                                <div class="card-body">
                                    <h6 class="card-title"><i class="bi {{ $icon }}"></i> {{ $name }}</h6>
                                    @if($conn)
                                        <p class="mb-1 text-success"><i class="bi bi-patch-check"></i> {{ __('Connected as') }} <strong>{{ $conn->provider_account_email ?: ucfirst($provider) }}</strong></p>
                                        <form method="POST" action="{{ route('cloud.disconnect', ['provider' => $provider]) }}" class="d-inline disconnect-form">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="scope" value="drive">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Disconnect') }}</button>
                                        </form>
                                    @elseif($configured)
                                        <a href="{{ route('cloud.start', ['provider' => $provider, 'scope' => 'drive']) }}" class="btn btn-sm btn-primary">
                                            <i class="bi bi-plug"></i> {{ __('Connect') }} {{ $name }}
                                        </a>
                                    @else
                                        <p class="text-muted mb-1">{{ __('Tenant admin must configure') }} {{ $name }} {{ __('credentials in Settings') }}.</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <form method="POST" action="{{ route('my-cloud.updatePhotos') }}" class="row g-2 align-items-end mt-3">
                    @csrf
                    @method('PUT')
                    <div class="col-md-5">
                        <label class="form-label">{{ __('Photo storage for my uploads') }}</label>
                        <select name="photo_storage" class="form-select" required>
                            <option value="cloud" @selected(($photoStorage ?? 'cloud') === 'cloud')>{{ __('CRM storage (hosting cloud)') }}</option>
                            @if(($googleConfigured ?? false))
                            <option value="google" @selected(($photoStorage ?? 'cloud') === 'google')>{{ __('Google Drive') }}</option>
                            @endif
                            @if(($microsoftConfigured ?? false))
                            <option value="microsoft" @selected(($photoStorage ?? 'cloud') === 'microsoft')>{{ __('Microsoft OneDrive') }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> {{ __('Save preference') }}</button>
                    </div>
                    @php $icalToken = auth()->user()->calendar_feed_token; @endphp
                    <div class="col-md-4">
                        @if($icalToken)
                        <a href="{{ route('calendar.feed', ['token' => $icalToken]) }}" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-ruled"></i> {{ __('My iCal feed') }}</a>
                        @else
                        <a href="{{ route('calendar.index') }}" class="btn btn-outline-secondary"><i class="bi bi-calendar2-week"></i> {{ __('Open calendar') }}</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('.disconnect-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (! confirm('{{ __('Disconnect this account? Events already synced will be removed from your calendar.') }}')) {
                e.preventDefault();
            }
        });
    });
</script>
@endpush