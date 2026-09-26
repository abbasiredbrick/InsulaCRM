@extends('layouts.app')
@section('title', __('Recycled Lead'))

@section('page-title', __('Recycled Lead'))

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

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if (session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('Contact') }}</h3>
                <div class="card-actions">
                    @if($recycled->category)<span class="badge bg-primary-lt">{{ __($recycled->category_label) }}</span>@endif
                    <span class="badge bg-primary-lt">{{ $recycled->portal_label }}</span>
                    <span class="badge bg-secondary-lt">{{ __($sources[$recycled->source] ?? $recycled->source) }}</span>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <span class="avatar avatar-lg me-3 bg-primary-lt">{{ strtoupper(substr($recycled->full_name ?: '?', 0, 1)) }}</span>
                    <div>
                        <h3 class="mb-0">{{ $recycled->full_name ?: '—' }}</h3>
                    </div>
                </div>

                <div class="mb-2">
                    @if($recycled->phone)
                        <a href="tel:{{ $recycled->phone }}" class="text-reset"><i class="bi bi-telephone me-1"></i>{{ $recycled->phone }}</a>
                        @if($recycled->whatsapp_phone)
                            <a href="https://wa.me/{{ $recycled->whatsapp_phone }}" target="_blank" rel="noopener" class="btn btn-sm btn-success ms-2">
                                {{ __('WhatsApp') }}
                            </a>
                        @endif
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
                <div class="mb-3 text-muted">
                    @if($recycled->email)
                        <a href="mailto:{{ $recycled->email }}" class="text-reset"><i class="bi bi-envelope me-1"></i>{{ $recycled->email }}</a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>

                @if($recycled->whatsapp_username)
                    <div class="mb-3 text-muted">
                        <i class="bi bi-at me-1"></i>{{ $recycled->whatsapp_username }}
                        <span class="text-muted small">({{ __('WhatsApp username') }})</span>
                    </div>
                @endif

                @if($recycled->call_recording_url)
                    <div class="mb-3">
                        <div class="form-label">{{ __('Call recording') }}</div>
                        <audio controls preload="none" src="{{ $recycled->call_recording_url }}" class="w-100">
                            {{ __('Your browser cannot play this recording.') }}
                        </audio>
                        <div class="form-hint">{{ __('Hosted by the portal — the link stops working once their access expires.') }}</div>
                    </div>
                @endif

                @if($recycled->status === 'already_active' && $recycled->linkedLead)
                    <div class="alert alert-cyan mb-0">
                        <i class="bi bi-link-45deg me-1"></i>
                        {{ __('This contact already exists as an active lead. Don\'t duplicate —') }}
                        <a href="{{ route('leads.edit', $recycled->linkedLead) }}" class="fw-bold text-reset">{{ __('open the existing lead') }}</a>.
                    </div>
                @endif

                @if($recycled->status === 'regenerated' && $recycled->regeneratedLead)
                    <div class="alert alert-teal mb-0">
                        <i class="bi bi-arrow-repeat me-1"></i>
                        {{ __('Regenerated into an active lead.') }}
                        <a href="{{ route('leads.edit', $recycled->regeneratedLead) }}" class="fw-bold text-reset">{{ __('Open regenerated lead') }}</a>.
                    </div>
                @endif
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('History') }}</h3>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">{{ __('Previous deal') }}</dt>
                    <dd class="col-7">{{ $recycled->deal_type_label }}</dd>

                    @if($recycled->source === 'auto_recycle' && $recycled->originalLead)
                        <dt class="col-5">{{ __('Original lead') }}</dt>
                        <dd class="col-7"><a href="{{ route('leads.edit', $recycled->originalLead) }}">{{ $recycled->originalLead->reference ?: '#' . $recycled->originalLead->id }}</a></dd>
                    @endif

                    @if($recycled->purchased_project)
                        <dt class="col-5">{{ __('Purchased project') }}</dt>
                        <dd class="col-7">{{ $recycled->purchased_project }}@if($recycled->unit_no) • {{ $recycled->unit_no }}@endif</dd>
                    @endif

                    @if($recycled->gross_price)
                        <dt class="col-5">{{ __('Invested / price') }}</dt>
                        <dd class="col-7">{{ \App\Helpers\TenantFormatHelper::currency((float) $recycled->gross_price) }}</dd>
                    @endif

                    @if($recycled->expected_handover_date)
                        <dt class="col-5">{{ __('Expected handover') }}</dt>
                        <dd class="col-7">
                            {{ $recycled->expected_handover_date->format('M d, Y') }}
                            <span class="text-muted small">({{ $recycled->expected_handover_date->isPast() ? __('past due') : $recycled->expected_handover_date->diffForHumans() }})</span>
                        </dd>
                    @endif

                    @if($recycled->recycled_at)
                        <dt class="col-5">{{ __('Recycled on') }}</dt>
                        <dd class="col-7">{{ $recycled->recycled_at->format('M d, Y') }}</dd>
                    @endif

                    @if($recycled->notes)
                        <dt class="col-12">{{ __('Notes') }}</dt>
                        <dd class="col-12 text-muted mb-0">{{ $recycled->notes }}</dd>
                    @endif
                </dl>

                @if($recycled->call_notes)
                    <hr>
                    <h6 class="text-uppercase text-muted">{{ __('Call log') }}</h6>
                    <pre class="text-muted small" style="white-space: pre-wrap; font-family: inherit; margin-bottom: 0;">{{ $recycled->call_notes }}</pre>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Log outcome') }}</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('recycled.status', $recycled) }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Status') }}</label>
                            <select name="status" class="form-select" required>
                                @foreach($statuses as $key => $label)
                                    <option value="{{ $key }}" {{ $recycled->status === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Agent') }}</label>
                            <select name="agent_id" class="form-select">
                                <option value="">{{ __('Unassigned') }}</option>
                                @foreach($agents as $id => $name)
                                    <option value="{{ $id }}" {{ $recycled->assignee_id == $id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Call back on') }}</label>
                            <input type="date" name="next_call_at" class="form-control" value="{{ $recycled->next_call_at?->format('Y-m-d') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label">{{ __('Call notes') }}</label>
                            <textarea name="call_notes" class="form-control" rows="3" placeholder="{{ __('What did they say? Is there a current interest we can chase?') }}"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">{{ __('Log outcome') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header" id="regenerate">
                <h3 class="card-title">{{ __('Regenerate to active lead') }}</h3>
                <div class="card-actions">
                    <span class="text-muted small">{{ __('Only after a positive outreach') }}</span>
                </div>
            </div>
            <div class="card-body">
                @if(in_array($recycled->status, ['regenerated', 'already_active'], true))
                    <div class="alert alert-warning mb-0">
                        {{ $recycled->status === 'regenerated' ? __('This contact was already regenerated.') : __('This contact is already an active lead — open the linked lead instead.') }}
                    </div>
                @elseif($recycled->status === 'do_not_contact')
                    <div class="alert alert-warning mb-0">{{ __('This contact asked not to be contacted.') }}</div>
                @else
                    <form method="POST" action="{{ route('recycled.regenerate', $recycled) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Regeneration intent') }}</label>
                                <select name="intent" id="regenerateIntent" class="form-select" required>
                                    <option value="">{{ __('— Choose the reason —') }}</option>
                                    @foreach($intents as $key => $label)
                                        <option value="{{ $key }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                <div class="form-hint mt-2" id="intentScript"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Assign to') }}</label>
                                <select name="agent_id" class="form-select">
                                    <option value="{{ auth()->id() }}" selected>{{ auth()->user()->name ?? '' }}</option>
                                    @foreach($agents as $id => $name)
                                        @if((int) $id !== (int) auth()->id())
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6" id="handoverDateWrap" style="display: none;">
                                <label class="form-label">{{ __('Expected handover date') }}</label>
                                <input type="date" name="handover_date" class="form-control" value="{{ $recycled->expected_handover_date?->format('Y-m-d') }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Regeneration notes') }}</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="{{ __('e.g. wants a 2BR in Marina under 120k; viewed 3 units at the last showing in 2024') }}"></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-teal">
                                    <i class="bi bi-arrow-repeat me-1"></i>{{ __('Regenerate lead') }}
                                </button>
                                <span class="form-hint d-inline-block ms-2">{{ __('Creates/revives an active lead with warm temperature, correct deal type, stage, and source — plus a handover follow-up when applicable.') }}</span>
                            </div>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div class="text-muted small">{{ __('Remove this record from the pool entirely.') }}</div>
                <form method="POST" action="{{ route('recycled.destroy', $recycled) }}" onsubmit="return confirm('{{ __('Remove this pooled contact?') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
(function () {
    const scripts = @json(\App\Models\RecycledLead::OUTREACH_SCRIPTS);
    const intentSelect = document.getElementById('regenerateIntent');
    const scriptBox = document.getElementById('intentScript');
    const handoverWrap = document.getElementById('handoverDateWrap');

    if (!intentSelect) return;

    const portal = @json($recycled->portal_label ?? '');
    const project = @json($recycled->purchased_project ?? '');

    function updateIntent() {
        const key = intentSelect.value;
        if (!key || !scripts[key]) {
            scriptBox.innerHTML = '';
            handoverWrap.style.display = 'none';
            return;
        }
        let text = scripts[key]
            .replace('{portal}', portal)
            .replace('{project}', project || 'your development')
            .replace('{area}', '');
        scriptBox.innerHTML = 'Suggested opener: <em>"' + text.replace(/"/g, '&quot;') + '"</em>';
        handoverWrap.style.display = key === 'handover_tracking' ? 'block' : 'none';
    }

    intentSelect.addEventListener('change', updateIntent);
    updateIntent();
})();
</script>
@endpush