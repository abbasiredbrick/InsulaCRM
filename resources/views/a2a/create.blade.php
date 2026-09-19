@extends('layouts.app')

@section('title', __('New A2A Contract'))
@section('page-title', __('New A2A Contract'))

@section('breadcrumbs')
<li class="breadcrumb-item"><a href="{{ route('a2a.index') }}">{{ __('A2A Contracts') }}</a></li>
<li class="breadcrumb-item active" aria-current="page">{{ __('New') }}</li>
@endsection

@section('content')
<form method="POST" action="{{ route('a2a.store') }}">
    @csrf
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title">{{ __('Counterparty') }}</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">{{ __('Full name') }}</label>
                            <input type="text" name="counterparty_name" class="form-control @error('counterparty_name') is-invalid @enderror" value="{{ old('counterparty_name') }}" required>
                            @error('counterparty_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Email') }}</label>
                            <input type="email" name="counterparty_email" class="form-control @error('counterparty_email') is-invalid @enderror" value="{{ old('counterparty_email') }}">
                            @error('counterparty_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Company / Agency') }}</label>
                            <input type="text" name="counterparty_company" class="form-control" value="{{ old('counterparty_company') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Address') }}</label>
                            <input type="text" name="counterparty_address" class="form-control" value="{{ old('counterparty_address') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">{{ __('Commission Share') }}</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">{{ __('Share (%)') }}</label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" max="100" name="share_pct" class="form-control @error('share_pct') is-invalid @enderror" value="{{ old('share_pct', 10) }}" required>
                                <span class="input-group-text">%</span>
                            </div>
                            @error('share_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label class="form-label required">{{ __('Funded by') }}</label>
                            <select name="funding_source" class="form-select" required>
                                <option value="from_agent" @selected(old('funding_source') === 'from_agent')>{{ __('Entirely from the main agent') }}</option>
                                <option value="from_company" @selected(old('funding_source') === 'from_company')>{{ __('Entirely from the company') }}</option>
                                <option value="from_both" @selected(old('funding_source') === 'from_both')>{{ __('Half company / half main agent') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-hint mt-2">
                        {{ __('Using :source governs how this share is deducted when a lead commission is calculated.', ['source' => __('Tenant commission settings')]) }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">{{ __('Terms') }}</h3></div>
                <div class="card-body">
                    <label class="form-label">{{ __('Special terms') }}</label>
                    <textarea name="terms" rows="8" class="form-control" placeholder="{{ __('Payment milestones, refund conditions, cancellation…)') }}">{{ old('terms') }}</textarea>
                </div>
                <div class="card-footer text-end">
                    <a href="{{ route('a2a.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('Create contract') }}</button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection