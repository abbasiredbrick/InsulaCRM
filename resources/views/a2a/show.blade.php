@extends('layouts.app')

@section('title', $contract->contract_number)
@section('page-title', $contract->contract_number)

@section('breadcrumbs')
<li class="breadcrumb-item"><a href="{{ route('a2a.index') }}">{{ __('A2A Contracts') }}</a></li>
<li class="breadcrumb-item active" aria-current="page">{{ $contract->contract_number }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Contract') }} {{ $contract->contract_number }}</h3>
                <div class="card-actions">
                    <span class="badge {{ $contract->isSigned() ? 'bg-green-lt' : ($contract->status === 'sent' ? 'bg-azure-lt' : ($contract->status === 'void' ? 'bg-secondary-lt' : 'bg-yellow-lt')) }}">
                        {{ ucfirst(__($contract->status)) }}
                    </span>
                    <a href="{{ route('a2a.print', $contract) }}" target="_blank" class="btn btn-outline-primary btn-sm">
                        {{ __('Print / Save as PDF') }}
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="datagrid">
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Prepared by') }}</div>
                        <div class="datagrid-content">{{ $contract->agent?->name ?? '—' }}</div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Counterparty') }}</div>
                        <div class="datagrid-content">{{ $contract->counterparty_name }}</div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Company / Agency') }}</div>
                        <div class="datagrid-content">{{ $contract->counterparty_company ?? '—' }}</div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Email') }}</div>
                        <div class="datagrid-content">{{ $contract->counterparty_email ?? '—' }}</div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Address') }}</div>
                        <div class="datagrid-content">{{ $contract->counterparty_address ?? '—' }}</div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Share') }}</div>
                        <div class="datagrid-content">{{ rtrim(rtrim((string) $contract->share_pct, '0'), '.') }}%</div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Funded by') }}</div>
                        <div class="datagrid-content">{{ $contract->funding_label }}</div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Sent') }}</div>
                        <div class="datagrid-content">{{ $contract->sent_at?->format('M d, Y') ?? '—' }}</div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">{{ __('Signed') }}</div>
                        <div class="datagrid-content">{{ $contract->signed_at?->format('M d, Y') ?? '—' }}</div>
                    </div>
                </div>
                @if($contract->terms)
                <div class="mt-3">
                    <div class="text-secondary text-uppercase fw-bold mb-1" style="font-size:0.75rem;">{{ __('Terms') }}</div>
                    <div style="white-space:pre-line;">{{ $contract->terms }}</div>
                </div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Advance the contract') }}</h3>
            </div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    @if($contract->status === 'draft')
                    <form method="POST" action="{{ route('a2a.markSent', $contract) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-primary">{{ __('Mark as sent') }}</button>
                    </form>
                    @endif

                    @if(! $contract->isSigned() && in_array($contract->status, ['draft','sent']))
                    <form method="POST" action="{{ route('a2a.uploadSigned', $contract) }}" enctype="multipart/form-data" class="d-inline-flex gap-2 align-items-center">
                        @csrf
                        <input type="file" name="signed_copy" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                        <button type="submit" class="btn btn-outline-green btn-sm">{{ __('Upload signed copy') }}</button>
                    </form>
                    @endif

                    @if($contract->isSigned() && $contract->signed_file_path)
                    <a href="{{ route('a2a.downloadSigned', $contract) }}" class="btn btn-outline-primary">{{ __('Download signed copy') }}</a>
                    @endif

                    @if($contract->status !== 'void' && $contract->status !== 'signed')
                    <form method="POST" action="{{ route('a2a.void', $contract) }}" onsubmit="return confirm('{{ __('Void this contract?') }}')">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('Void') }}</button>
                    </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        @if($contract->isSigned())
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Attach to a lead') }}</h3>
            </div>
            <div class="card-body">
                @if($attachLeads->isNotEmpty())
                <form method="POST" action="{{ route('a2a.attach', $contract) }}">
                    @csrf
                    <label class="form-label mb-1">{{ __('Add counterparty as external agent on') }}</label>
                    <select name="lead_id" class="form-select form-select-sm mb-2" required>
                        <option value="">{{ __('Select lead...') }}</option>
                        @foreach($attachLeads as $_lead)
                        <option value="{{ $_lead->id }}">{{ $_lead->full_name }} — {{ $_lead->phone }}</option>
                        @endforeach
                    </select>
                    <p class="text-secondary small mb-2">
                        {{ __('Shares of this contract (:pct, :funding) will be applied to the lead and its commission split.', [
                            'pct' => rtrim(rtrim((string) $contract->share_pct, '0'), '.') . '%',
                            'funding' => strtolower($contract->funding_label),
                        ]) }}
                    </p>
                    <button type="submit" class="btn btn-primary btn-sm w-100">{{ __('Attach to lead') }}</button>
                </form>
                @else
                <p class="text-secondary mb-0">{{ __('No leads owned by you yet. Create a lead first, then attach this contract.') }}</p>
                @endif
            </div>
        </div>
        @endif

        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Printed contract') }}</h3></div>
            <div class="card-body">
                <p class="text-secondary small mb-2">
                    {{ __('Open the printable version, then save it as PDF in the print dialog to email it to the counterparty.') }}
                </p>
                <a href="{{ route('a2a.print', $contract) }}" class="btn btn-outline-primary w-100" target="_blank">
                    {{ __('Open printable contract') }}
                </a>
            </div>
        </div>
    </div>
</div>
@endsection