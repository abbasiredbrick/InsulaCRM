@extends('layouts.app')

@section('title', __('Unit Map Locations'))
@section('page-title', __('Unit Map Locations'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-0">
            {{ __('Each building has one Google Maps location shared by all of its units. Sheets often publish it as an unreadable cell hyperlink (AMS), a proper column (Reelam) or a line before the table (AY) — anything the import could not read is left blank. Set or correct the location per building here.') }}
        </p>
        <p class="mb-0 mt-1 text-muted">
            <strong>{{ $missing }}</strong> {{ __('building(s) still have no location.') }}
            <a href="{{ route('availability-sources.locations-apply') }}" class="text-decoration-none"
               onclick="event.preventDefault(); this.closest('form')?.submit(); return confirm('{{ __('Generate Google Maps search links for every building that still lacks one?') }}')">(&nbsp;{{ __('Generate for all missing') }}&nbsp;)</a>
        </p>
        <form action="{{ route('availability-sources.locations-apply') }}" method="POST" class="d-none">
            @csrf
            <input type="hidden" name="building" value="__all__">
            <input type="hidden" name="mode" value="auto">
        </form>
    </div>
    <a href="{{ route('availability-sources.index') }}" class="btn btn-link text-decoration-none text-muted">← {{ __('Back to sources') }}</a>
</div>

@if($groups->isEmpty())
    <div class="empty">
        <p class="empty-title">{{ __('No units found') }}</p>
        <p class="empty-subtitle text-muted">{{ __('Import an availability sheet first, then come back to review its locations.') }}</p>
    </div>
@else
    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ __('Building') }}</th>
                        <th>{{ __('Community') }}</th>
                        <th>{{ __('City') }}</th>
                        <th class="text-center">{{ __('Units') }}</th>
                        <th style="min-width:360px">{{ __('Location / Map link') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($groups as $group)
                        <tr>
                            <td class="fw-bold">{{ $group->sub_community }}</td>
                            <td>{{ $group->community ?: '—' }}</td>
                            <td>{{ $group->city ?: '—' }}</td>
                            <td class="text-center">
                                <span class="badge bg-azure-lt">{{ $group->unit_count }}</span>
                            </td>
                            <td>
                                <form action="{{ route('availability-sources.locations-apply') }}" method="POST" class="d-flex gap-2">
                                    @csrf
                                    <input type="hidden" name="building" value="{{ $group->sub_community }}">
                                    <input type="hidden" name="community" value="{{ $group->community }}">
                                    <input type="hidden" name="city" value="{{ $group->city }}">
                                    <input type="text" name="location" class="form-control form-control-sm" value="{{ $group->prefill ?: '' }}" placeholder="Google Maps link, or a place name">
                                    <button type="submit" name="mode" value="save" class="btn btn-sm btn-primary text-nowrap">{{ __('Save') }}</button>
                                    <button type="submit" name="mode" value="auto" class="btn btn-sm btn-outline-secondary text-nowrap">{{ __('Generate') }}</button>
                                    <button type="submit" name="mode" value="clear" class="btn btn-sm btn-link text-danger text-decoration-none text-nowrap">{{ __('Clear') }}</button>
                                </form>
                                <div class="mt-1" style="font-size:0.75rem;">
                                    @if($group->map_url)
                                        <a href="{{ $group->map_url }}" target="_blank" rel="noopener" class="text-decoration-none">
                                            {{ \Illuminate\Support\Str::limit($group->map_url, 80) }}
                                        </a>
                                    @elseif($group->suggested)
                                        <span class="text-secondary">{{ __('Suggested:') }}</span>
                                        <a href="{{ $group->suggested }}" target="_blank" rel="noopener" class="text-decoration-none">
                                            {{ \Illuminate\Support\Str::limit($group->suggested, 60) }}
                                        </a>
                                    @else
                                        <span class="text-muted">{{ __('No location yet.') }}</span>
                                    @endif
                                    @if($group->current_query)
                                        <span class="text-muted d-block">「{{ $group->current_query }}」</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection