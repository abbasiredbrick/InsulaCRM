@extends('layouts.app')

@section('title', __('My Commissions'))
@section('page-title', __('My Commissions'))

@section('breadcrumbs')
<li class="breadcrumb-item active" aria-current="page">{{ __('My Commissions') }}</li>
@endsection

@section('content')
<div class="row mb-3 g-2">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-secondary small">{{ __('Outstanding balance') }}</div>
                <div class="h2 mb-0">{{ \App\Helpers\TenantFormatHelper::currency($balance) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-secondary small">{{ __('Earned (unpaid)') }}</div>
                <div class="h2 mb-0 text-yellow">{{ \App\Helpers\TenantFormatHelper::currency($earned->sum(fn ($c) => (float) $c->amount)) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-secondary small">{{ __('Paid') }}</div>
                <div class="h2 mb-0 text-green">{{ \App\Helpers\TenantFormatHelper::currency($paid->sum(fn ($c) => (float) $c->amount)) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Commission Ledger') }}</h3>
    </div>
    <div class="card-body">
        @if($commissions->isEmpty())
            <p class="text-secondary mb-0">{{ __('No commissions yet. Shared, closed leads with a calculated split appear here.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-vcenter">
                <thead>
                    <tr>
                        <th>{{ __('Lead') }}</th>
                        <th>{{ __('Gross') }}</th>
                        <th>{{ __('Share') }}</th>
                        <th class="text-end">{{ __('Your amount') }}</th>
                        <th>{{ __('Basis') }}</th>
                        <th>{{ __('Funding') }}</th>
                        <th>{{ __('Closed / calculated') }}</th>
                        <th>{{ __('Status') }}</th>
                        @if(auth()->user()->isAdmin())
                        <th class="text-end">{{ __('Actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($commissions as $cm)
                    <tr>
                        <td>
                            @if($cm->lead)
                            <a href="{{ route('leads.show', $cm->lead) }}" class="text-reset fw-semibold">{{ $cm->lead->full_name }}</a>
                            @else
                            {{ $cm->participant_name ?? __('Lead #:id', ['id' => $cm->lead_id]) }}
                            @endif
                        </td>
                        <td>{{ \App\Helpers\TenantFormatHelper::currency($cm->gross_commission) }}</td>
                        <td>{{ rtrim(rtrim((string) $cm->share_pct, '0'), '.') }}%</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\TenantFormatHelper::currency($cm->amount) }}</td>
                        <td>
                            @if($cm->basis === 'tiered')
                                <span class="badge bg-purple-lt">{{ __('Tiered') }}</span>
                            @elseif($cm->basis === 'fixed_amount')
                                <span class="badge bg-orange-lt">{{ __('Fixed amount') }}</span>
                            @elseif($cm->basis === 'support')
                                <span class="badge bg-azure-lt">{{ __('Support share') }}</span>
                            @else
                                <span class="badge bg-green-lt">{{ __('Fixed split') }}</span>
                            @endif
                        </td>
                        <td class="text-secondary small">{{ $cm->funding_label }}</td>
                        <td class="small">{{ $cm->commissioned_at?->format('M d, Y') ?? '—' }}</td>
                        <td>
                            <span class="badge {{ $cm->isPaid() ? 'bg-green-lt' : ($cm->status === 'void' ? 'bg-secondary-lt' : 'bg-yellow-lt') }}">
                                {{ ucfirst(__($cm->status)) }}
                            </span>
                        </td>
                        @if(auth()->user()->isAdmin())
                        <td class="text-end">
                            <form method="POST" action="{{ route('commissions.status', $cm) }}" class="d-inline">
                                @csrf
                                @method('PATCH')
                                <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                    <option value="earned" @selected($cm->status === 'earned')>{{ __('Earned') }}</option>
                                    <option value="paid" @selected($cm->status === 'paid')>{{ __('Paid') }}</option>
                                    <option value="void" @selected($cm->status === 'void')>{{ __('Void') }}</option>
                                </select>
                            </form>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection