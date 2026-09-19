@extends('layouts.app')
@section('title', __('Import Cold Call List'))

@section('page-title', __('Import Cold Call List'))

@section('content')

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('Import a cold-call list') }}</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('market.import') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">{{ __('List name') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __('e.g. Marina Tower Owners Sep 2026') }}" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ __('List type') }} <span class="text-danger">*</span></label>
                        <select name="type" id="listType" class="form-select" required>
                            @foreach($importTypes as $key => $label)
                                <option value="{{ $key }}" {{ old('type') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <div class="form-hint mt-2" id="typeHint">
                            {{ __('Landlord rows are used to bring units into inventory; investor rows generate sales leads.') }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ __('Excel / CSV file') }} <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" accept=".xlsx,.csv,.txt" required>
                        <div class="form-hint">{{ __('.xlsx, .csv or .txt (up to 10 MB). First row must be the column header.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ __('Default agent') }}</label>
                        <select name="agent_id" class="form-select">
                            <option value="">{{ __('Unassigned — pick the calling agent at conversion') }}</option>
                            @foreach($agents as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        {{ __('Import & build list') }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('Expected columns') }}</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">{{ __('The importer recognises these header names (case-insensitive). Only Name or Phone is required per row.') }}</p>

                <h6 class="text-uppercase text-muted">{{ __('Contact') }}</h6>
                <ul class="text-muted small">
                    <li><code>Name</code> or <code>First Name</code> / <code>Last Name</code></li>
                    <li><code>Phone</code> (also <i>Mobile</i>, <i>Tel</i>, <i>WhatsApp</i>)</li>
                    <li><code>Email</code>, <code>Company</code></li>
                    <li><code>Type</code> (optional per row: <i>landlord</i> / <i>investor</i>)</li>
                </ul>

                <h6 class="text-uppercase text-muted">{{ __('Unit (for landlord lists)') }}</h6>
                <ul class="text-muted small">
                    <li><code>Unit No</code>, <code>Building</code>, <code>Address</code></li>
                    <li><code>Community</code>, <code>Property Type</code></li>
                    <li><code>Bedrooms</code>, <code>Bathrooms</code></li>
                    <li><code>Rent</code> (also <i>Annual Rent</i>, <i>Asking Rent</i>)</li>
                    <li><code>Status</code> (unit availability, optional)</li>
                </ul>

                <h6 class="text-uppercase text-muted">{{ __('Interest (for investor lists)') }}</h6>
                <ul class="text-muted small">
                    <li><code>Budget</code>, <code>Preferred Type</code></li>
                    <li><code>Requirements</code> (also <i>Notes</i>, <i>Remarks</i>)</li>
                </ul>

                <div class="alert alert-info mt-3 mb-0">
                    <strong>{{ __('What happens next?') }}</strong>
                    {{ __('The list appears under Cold Calls. After a successful cold call you convert a landlord row to inventory (unit under the calling agent, owner saved) or an investor row to a sales lead — details stay editable.') }}
                </div>
            </div>
        </div>
    </div>
</div>

@endsection