@extends('layouts.app')

@section('title', __('Listed Units Awaiting a Decision'))
@section('page-title', __('Listed Units Awaiting a Decision'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-0">
            {{ __('These units are currently listed, but the PM availability sheet shows them as leased. Since the madhmoun listing permit is costly to re-issue, they were NOT unlisted automatically. Choose to keep them listed (leads keep coming in and can be diverted to other units) or unlist them.') }}
        </p>
    </div>
    <a href="{{ route('availability-sources.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Back to Inventory Sources') }}</a>
</div>

@if($pending->isEmpty())
<div class="card mb-3">
    <div class="card-body empty">
        <p class="empty-title">{{ __('Nothing awaiting a decision') }}</p>
        <p class="empty-subtitle text-muted">{{ __('Listed units only reach this queue when a refreshed PM sheet shows them as leased.') }}</p>
    </div>
</div>
@else
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title mb-0">{{ __('Needs a decision') }} ({{ $pending->count() }})</h3></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Building / Community') }}</th>
                    <th>{{ __('Source') }}</th>
                    <th>{{ __('Reason') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($pending as $review)
                <tr>
                    <td>
                        @if($review->property)
                            <a href="{{ route('inventory.show', $review->property) }}" class="fw-bold">{{ $review->property->display_name }}</a>
                            <div class="text-muted small">{{ $review->property->unit_no }}</div>
                        @else
                            <span class="text-muted">#{{ $review->property_id }}</span>
                        @endif
                    </td>
                    <td>
                        @if($review->property)
                            <div>{{ $review->property->sub_community ?: $review->property->community }}</div>
                            <div class="text-muted small">
                                @if($review->property->rent_price)
                                    {{ \App\Helpers\TenantFormatHelper::currency($review->property->rent_price) }}
                                @endif
                                {{ $review->property->bedrooms !== null ? $review->property->bedrooms . ' BR' : '' }}
                            </div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($review->source)
                            <span class="badge bg-azure-lt">{{ $review->source->name }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <div>{{ __($review->reason_label) }}</div>
                        @if($review->notes)
                            <div class="text-muted small">{{ $review->notes }}</div>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <div class="d-flex gap-2">
                            <form method="POST" action="{{ route('availability-sources.reviews.resolve', $review) }}" onsubmit="return confirm('{{ __('Keep this unit listed? It stays live on the portals and keeps receiving leads, which you can divert to other units.') }}')">
                                @csrf
                                <input type="hidden" name="action" value="keep_listed">
                                <button class="btn btn-sm btn-success" title="{{ __('Keep the portal listing — divert incoming leads to other units') }}">{{ __('Keep Listed') }}</button>
                            </form>
                            <form method="POST" action="{{ route('availability-sources.reviews.resolve', $review) }}" onsubmit="return confirm('{{ __('Unlist this unit? It will stop showing as available. (Re-listing later may need a new madhmoun permit.)') }}')">
                                @csrf
                                <input type="hidden" name="action" value="unlist">
                                <button class="btn btn-sm btn-outline-danger">{{ __('Unlist') }}</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@if($resolved->isNotEmpty())
<div class="card">
    <div class="card-header"><h3 class="card-title mb-0">{{ __('Recently decided') }}</h3></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Source') }}</th>
                    <th>{{ __('Decision') }}</th>
                    <th>{{ __('By') }}</th>
                    <th>{{ __('Decided') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($resolved as $review)
                <tr>
                    <td>
                        @if($review->property)
                            <a href="{{ route('inventory.show', $review->property) }}">{{ $review->property->display_name }}</a>
                        @else
                            <span class="text-muted">#{{ $review->property_id }}</span>
                        @endif
                    </td>
                    <td>{{ $review->source?->name ?: '—' }}</td>
                    <td>
                        @if($review->status === 'keep_listed')
                            <span class="badge bg-green-lt">{{ __('Kept listed') }}</span>
                        @else
                            <span class="badge bg-red-lt">{{ __('Unlisted') }}</span>
                        @endif
                    </td>
                    <td>{{ $review->decider?->name ?: '—' }}</td>
                    <td class="text-muted">{{ $review->decided_at?->format('d M Y, H:i') ?: '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection