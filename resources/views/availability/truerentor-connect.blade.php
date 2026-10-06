@extends('layouts.app')

@section('title', __('Connect TrueRentor'))
@section('page-title', __('Connect TrueRentor'))

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">{{ __('Seamless (recommended)') }}</h3></div>
            <div class="card-body">
                <p class="text-muted">{{ __('Sign in to TrueRentor and approve the connection — your inventory syncs automatically, no token to copy.') }}</p>
                <form method="POST" action="{{ route('availability-sources.truerentor.oauth') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">{{ __('Operator slug') }} <span class="text-danger">*</span></label>
                        <input type="text" name="org_slug" required class="form-control" value="{{ old('org_slug') }}" placeholder="{{ __('e.g. ams-properties') }}">
                        <div class="form-hint">{{ __('The part of the operator\'s TrueRentor link after /p/ (e.g. /p/ams-properties → ams-properties).') }}</div>
                    </div>
                    <button class="btn btn-primary">{{ __('Connect with TrueRentor') }}</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Paste a token (standalone)') }}</h3></div>
            <div class="card-body">
                <p class="text-muted">{{ __('For any TrueRentor operator: copy the API token from your broker portal, then paste it here.') }}</p>
                <form method="POST" action="{{ route('availability-sources.truerentor.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">{{ __('Operator slug') }} <span class="text-danger">*</span></label>
                        <input type="text" name="org_slug" required class="form-control" value="{{ old('org_slug') }}" placeholder="{{ __('e.g. ams-properties') }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('API token') }} <span class="text-danger">*</span></label>
                        <input type="text" name="api_token" required class="form-control" value="{{ old('api_token') }}" placeholder="{{ __('paste the token from TrueRentor') }}">
                    </div>
                    <button class="btn btn-outline-primary">{{ __('Add TrueRentor source') }}</button>
                    <a href="{{ route('availability-sources.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
