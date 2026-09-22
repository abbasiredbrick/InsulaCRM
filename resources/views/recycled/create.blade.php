@extends('layouts.app')
@section('title', __('Import Previous Leads'))

@section('page-title', __('Import Previous Leads'))

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

@if (session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('Import your previous portal leads') }}</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('recycled.import') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">{{ __('Import name (optional)') }}</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __('e.g. Bayut leads 2024–2025') }}">
                        <div class="form-hint">{{ __('Just a label to remember the batch.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ __('Portal') }} <span class="text-danger">*</span></label>
                        <select name="portal" id="recycledPortal" class="form-select" required>
                            @foreach($portals as $key => $label)
                                <option value="{{ $key }}" {{ old('portal') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <div class="form-hint mt-2">{{ __('Used as the lead source for regenerated leads. If your file has a source/portal column, that value wins per row.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ __('Excel / CSV file') }} <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" accept=".xlsx,.csv,.txt" required>
                        <div class="form-hint">{{ __('.xlsx, .csv or .txt (up to 10 MB). First row must be the column header.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ __('Default assignee') }}</label>
                        <select name="agent_id" class="form-select">
                            <option value="">{{ __('Unassigned — pick an agent when working the pool') }}</option>
                            @foreach($agents as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        {{ __('Import & build recycle bank') }}
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
                <p class="text-muted">{{ __('The importer recognises these header names (case-insensitive) from Bayut / Dubizzle / PropertyFinder exports. Only Name or Phone is required per row.') }}</p>

                <h6 class="text-uppercase text-muted">{{ __('Contact') }}</h6>
                <ul class="text-muted small">
                    <li><code>Name</code> or <code>First Name</code> / <code>Last Name</code></li>
                    <li><code>Phone</code> (also <i>Mobile</i>, <i>Tel</i>, <i>WhatsApp</i>)</li>
                    <li><code>Email</code>, <code>Reference</code> (lead / enquiry id)</li>
                    <li><code>Portal</code> / <code>Source</code> (optional per row)</li>
                </ul>

                <h6 class="text-uppercase text-muted">{{ __('Transaction history') }}</h6>
                <ul class="text-muted small">
                    <li><code>Deal Type</code> / <code>Purpose</code> / <code>Transaction Type</code> (rent / buy)</li>
                    <li><code>Project</code>, <code>Unit No</code>, <code>Price</code></li>
                    <li><code>Handover Date</code> (for past buyers with a unit on the way)</li>
                    <li><code>Notes</code> / <code>Comments</code></li>
                </ul>

                <div class="alert alert-info mt-3 mb-0">
                    <strong>{{ __('What happens next?') }}</strong>
                    {{ __('Contacts go into the Recycled Leads pool — separate from your live pipeline. Contacts that already match an active lead are flagged "Already Active" and linked instead of duplicated. Work the pool by cold call or WhatsApp, log the outcome, then Regenerate any positive contact into an active lead with the right intent (relocate, buy, invest, lease out the unit, or track handover).') }}
                </div>
            </div>
        </div>
    </div>
</div>

@endsection