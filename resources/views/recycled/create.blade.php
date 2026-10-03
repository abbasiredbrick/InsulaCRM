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

<div class="card mb-3">
    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
            <h3 class="card-title">{{ __('Already have API access?') }}</h3>
            <div class="text-muted small">{{ __('Pull previous enquiries directly from Bayut, Dubizzle, or Property Finder (last 89 days), preview the result, then confirm what enters the pool.') }}</div>
        </div>
        <a href="{{ route('recycled.portal-import.create') }}" class="btn btn-outline-primary">{{ __('Pull via Portal API') }}</a>
    </div>
</div>

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
                        <label class="form-label">{{ __('Category') }}</label>
                        <select name="category" class="form-select">
                            <option value="">{{ __('Auto-detect from file (recommended)') }}</option>
                            @foreach($categories as $key => $label)
                                <option value="{{ $key }}" {{ old('category') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <div class="form-hint mt-2">{{ __('How the leads contacted you. Bayut report exports are detected automatically (WhatsApp / Phone / Email logs), so this override is only for files where auto-detection reads the wrong channel.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ __('Excel / CSV file') }} <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" accept=".xlsx,.csv,.txt" required>
                        <div class="form-hint">{{ __('.xlsx, .csv or .txt (up to 10 MB). Bayut report exports with a title/preamble row are detected automatically.') }}</div>
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
                <p class="text-muted">{{ __('The importer recognises these header names (case-insensitive) from Bayut / Dubizzle / PropertyFinder exports. A phone, WhatsApp number or email is required per row.') }}</p>

                <div class="alert alert-info py-2 small">
                    <strong>{{ __('Property Finder history:') }}</strong>
                    {{ __('The PF API only reaches back 89 days, so older history comes from PF Expert: export up to 180 days per file (3 years available) from Leads → Custom range → Export, then upload each file here.') }}
                    <a href="https://support.propertyfinder.ae/hc/en-us/articles/36865116212754-Leads-Export" target="_blank" rel="noopener">{{ __('PF export guide') }}</a>
                </div>

                <h6 class="text-uppercase text-muted">{{ __('Property Finder columns') }}</h6>
                <ul class="text-muted small">
                    <li><code>Channel</code> — the row's lead category (WhatsApp / call / email)</li>
                    <li><code>Sender Name</code> / <code>Sender Phone</code> / <code>Sender Email</code> — the contact</li>
                    <li><code>WhatsApp Username</code> — kept as its own field; a handle is a contact route on its own</li>
                    <li><code>Call Record File</code> — played inline on the pool record (the portal's link expires eventually)</li>
                    <li><code>Listing Reference</code> — for Pristine, a <code>-R-</code> segment marks a rental and <code>-S-</code> a sale</li>
                    <li><code>Tags</code> — rows tagged <code>from_agent</code> are your own agent's enquiries and are dropped</li>
                </ul>

                <h6 class="text-uppercase text-muted">{{ __('Contact') }}</h6>
                <ul class="text-muted small">
                    <li><code>Name</code> or <code>First Name</code> / <code>Last Name</code></li>
                    <li><code>Phone</code> / <code>Mobile</code> / <code>WhatsApp</code> — stored internationally, e.g. <code>+971 50 123 4567</code></li>
                    <li><code>Email</code>, <code>Reference</code> (lead / enquiry id)</li>
                    <li><code>Portal</code> / <code>Source</code> (optional per row)</li>
                </ul>

                <div class="alert alert-warning py-2 small mb-3">
                    <strong>{{ __('Rows without any phone, WhatsApp number, WhatsApp username or email are dropped.') }}</strong>
                    {{ __('A contact with no reachable number, handle or address has no value — the import report tells you how many were discarded for this reason.') }}
                </div>

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