@extends('layouts.app')

@section('title', __('Service Provider Review'))
@section('page-title', __('Service Provider Review'))

@section('page-actions')
    <a href="{{ route('service-providers.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Directory') }}</a>
    <a href="{{ route('service-providers.links') }}" class="btn btn-sm btn-outline-primary">{{ __('Registration links') }}</a>
@endsection

@section('content')
@php
    $statusColors = ['pending' => 'bg-yellow-lt', 'changes_requested' => 'bg-orange-lt', 'approved' => 'bg-green-lt', 'rejected' => 'bg-red-lt'];
@endphp

<div class="card mb-3">
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('service-providers.review') }}" id="review-filter-form" data-live-filter>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Status') }}</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">{{ __('All') }}</option>
                        @foreach($statuses as $key => $label)
                            <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>
                                {{ __($label) }} ({{ $counts[$key] ?? 0 }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    @if(request()->filled('status'))
                        <a href="{{ route('service-providers.review') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
                    @endif
                </div>
            </div>
        </form>
    </div>

    <div data-live-results>
        @forelse($providers as $provider)
            <div class="card-body border-bottom">
                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                    <div>
                        <a href="{{ route('service-providers.show', $provider) }}" class="fw-semibold text-reset">{{ $provider->company_name }}</a>
                        <span class="badge ms-1 {{ $statusColors[$provider->status] ?? 'bg-secondary-lt' }}">{{ $provider->statusLabel() }}</span>
                        <span class="badge bg-blue-lt ms-1">{{ __($provider->categoryLabel()) }}</span>
                    </div>
                    <div class="text-muted small">{{ $provider->created_at->format('M j, Y g:i A') }}</div>
                </div>

                <div class="row g-2 small mb-2">
                    <div class="col-md-3"><span class="text-muted">{{ __('Representative') }}</span> {{ $provider->representative_name }}</div>
                    <div class="col-md-2"><span class="text-muted">{{ __('License') }}</span> {{ $provider->trade_license_number }}</div>
                    <div class="col-md-3"><span class="text-muted">{{ __('Mobile') }}</span> {{ $provider->mobile }}</div>
                    <div class="col-md-4"><span class="text-muted">{{ __('Email') }}</span> {{ $provider->email }}</div>
                </div>

                <div class="d-flex flex-wrap gap-1 mb-2">
                    @foreach($provider->documents as $document)
                        <a href="{{ route('service-providers.documents.download', $document) }}" class="btn btn-sm btn-outline-secondary btn-pill">{{ $document->typeLabel() }}</a>
                    @endforeach
                </div>

                @if($provider->reviews->whereNotNull('comment')->isNotEmpty())
                    <div class="text-muted small">
                        {{ $provider->reviews->whereNotNull('comment')->sortByDesc('created_at')->first()->comment }}
                    </div>
                @endif

                @if($provider->isUnderReview())
                    <form method="POST" action="{{ route('service-providers.review.store', $provider) }}" class="mt-2 row g-2 align-items-end">
                        @csrf
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Decision') }}</label>
                            <select name="action" class="form-select form-select-sm" data-review-action required>
                                <option value="approved">{{ __('Approve') }}</option>
                                <option value="changes_requested">{{ __('Request changes') }}</option>
                                <option value="rejected">{{ __('Reject') }}</option>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" data-comment-label>{{ __('Comment (required for rejection)') }}</label>
                            <input type="text" name="comment" class="form-control form-control-sm" maxlength="2000">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Submit decision') }}</button>
                        </div>
                    </form>
                @else
                    <div class="text-muted small">
                        {{ __('Final decision') }}:
                        @if($provider->approved_at) {{ __('Approved') }} {{ $provider->approved_at->format('M j, Y') }} @endif
                        @if($provider->rejected_at) {{ __('Rejected') }} {{ $provider->rejected_at->format('M j, Y') }} @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="card-body text-center text-muted py-4">{{ __('No applications match.') }}</div>
        @endforelse

        <div class="card-body border-top">{{ $providers->links() }}</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        document.addEventListener('change', function (event) {
            var select = event.target.closest('[data-review-action]');
            if (!select) return;
            var row = select.closest('form');
            var comment = row.querySelector('[name="comment"]');
            var label = row.querySelector('[data-comment-label]');
            var needs = select.value === 'rejected';
            comment.required = needs;
            label.textContent = needs ? '{{ __("Reason (required)") }}' : '{{ __("Comment (required for rejection)") }}';
        });
    })();
</script>
@endpush