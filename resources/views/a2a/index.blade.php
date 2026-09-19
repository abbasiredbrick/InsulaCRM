@extends('layouts.app')

@section('title', __('A2A Contracts'))
@section('page-title', __('A2A Contracts'))

@section('breadcrumbs')
<li class="breadcrumb-item active" aria-current="page">{{ __('A2A Contracts') }}</li>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Agent-to-Agent Sharing Contracts') }}</h3>
        <div class="card-actions">
            <a href="{{ route('a2a.create') }}" class="btn btn-primary">+ {{ __('New Contract') }}</a>
        </div>
    </div>
    <div class="card-body">
        @if($contracts->isEmpty())
            <p class="text-secondary mb-0">{{ __('No contracts yet. Create one to share commission with an external agent or freelancer.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-vcenter">
                <thead>
                    <tr>
                        <th>{{ __('Number') }}</th>
                        <th>{{ __('Counterparty') }}</th>
                        <th>{{ __('Company') }}</th>
                        <th>{{ __('Share') }}</th>
                        <th>{{ __('Funding') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Sent / Signed') }}</th>
                        <th class="text-end">{{ __('') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contracts as $contract)
                    <tr>
                        <td><a href="{{ route('a2a.show', $contract) }}" class="fw-semibold text-reset">{{ $contract->contract_number }}</a></td>
                        <td>{{ $contract->counterparty_name }}</td>
                        <td class="text-secondary">{{ $contract->counterparty_company ?? '—' }}</td>
                        <td>{{ rtrim(rtrim((string) $contract->share_pct, '0'), '.') }}%</td>
                        <td class="small text-secondary">{{ $contract->funding_source }}</td>
                        <td>
                            @if($contract->isSigned())
                                <span class="badge bg-green-lt">{{ __('Signed') }}</span>
                            @elseif($contract->status === 'sent')
                                <span class="badge bg-azure-lt">{{ __('Sent') }}</span>
                            @elseif($contract->status === 'void')
                                <span class="badge bg-secondary-lt">{{ __('Void') }}</span>
                            @else
                                <span class="badge bg-yellow-lt">{{ __('Draft') }}</span>
                            @endif
                        </td>
                        <td class="small text-secondary">
                            @if($contract->signed_at)
                                {{ $contract->signed_at->format('M d, Y') }}
                            @elseif($contract->sent_at)
                                {{ $contract->sent_at->format('M d, Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('a2a.show', $contract) }}" class="btn btn-sm btn-outline"> {{ __('View') }}</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection