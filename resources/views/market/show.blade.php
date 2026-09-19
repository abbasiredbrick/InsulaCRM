@extends('layouts.app')
@section('title', $contact->full_name . ' — ' . __('Cold Call Contact'))

@section('page-title', __('Cold Call Contact'))

@section('content')
@php
    $fmt = \App\Helpers\TenantFormatHelper::class;
    $statusBadges = [
        'pending' => 'secondary',
        'reached' => 'green',
        'not_reached' => 'yellow',
        'wrong_number' => 'red',
        'not_interested' => 'red',
        'call_back' => 'blue',
        'do_not_contact' => 'dark',
        'converted' => 'teal',
    ];
@endphp

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-{{ $statusBadges[$contact->status] ?? 'secondary' }} text-white">{{ strtoupper(substr($contact->full_name, 0, 1)) }}</span></div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $contact->full_name ?: __('Unknown') }}</div>
                        <div class="text-muted small">{{ __(\App\Models\MarketContact::TYPES[$contact->type] ?? $contact->type) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="small text-muted">{{ __('Status') }}</div>
                <div class="fw-semibold">{{ __($contact->status) }}</div>
                @if($contact->last_called_at)<div class="text-muted small">{{ __('Last call') }}: {{ $contact->last_called_at->format('j M Y, g:i a') }}</div>@endif
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="small text-muted">{{ __('Assigned agent') }}</div>
                <div class="fw-semibold">{{ $contact->caller?->name ?: '—' }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="small text-muted">{{ __('Import') }}</div>
                <div class="fw-semibold">{{ $contact->import?->name ?: '—' }}</div>
                <div class="text-muted small">{{ $contact->created_at->format('j M Y') }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- Left: Contact + Unit details --}}
    <div class="col-lg-7">

        <div class="card mb-3" id="call">
            <div class="card-header"><h3 class="card-title">{{ __('Record Call') }}</h3></div>
            <div class="card-body">
                <form method="POST" action="{{ route('market.status', $contact) }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Call outcome') }} <span class="text-danger">*</span></label>
                            <select name="status" class="form-select" required>
                                @foreach($statuses as $k => $v)
                                    <option value="{{ $k }}" {{ $k === 'reached' ? '' : '' }}>{{ __($v) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Calling agent') }}</label>
                            <select name="agent_id" class="form-select">
                                <option value="">{{ __('Same as current') }}</option>
                                @foreach($agents as $id => $name)
                                    <option value="{{ $id }}" {{ $id === ($contact->called_by ?? auth()->id()) ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">{{ __('Log call') }}</button>
                        </div>
                        <div class="col-12 mt-2">
                            <textarea name="call_notes" rows="2" class="form-control" placeholder="{{ __('Call notes…') }}">{{ old('call_notes', $contact->call_notes) }}</textarea>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ __('Contact & unit details') }}</h3></div>
            <div class="card-body">
                <form method="POST" action="{{ route('market.update', $contact) }}">
                    @csrf
                    @method('PATCH')

                    <h6 class="text-uppercase text-muted mb-2">{{ __('Contact') }}</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Type') }}</label>
                            <select name="type" class="form-select form-select-sm" required>
                                @foreach(\App\Models\MarketContact::TYPES as $k => $v)
                                    <option value="{{ $k }}" {{ $k === $contact->type ? 'selected' : '' }}>{{ __($v) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('First name') }}</label>
                            <input type="text" name="first_name" value="{{ $contact->first_name }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Last name') }}</label>
                            <input type="text" name="last_name" value="{{ $contact->last_name }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Phone') }}</label>
                            <input type="text" name="phone" value="{{ $contact->phone }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Email') }}</label>
                            <input type="email" name="email" value="{{ $contact->email }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Company') }}</label>
                            <input type="text" name="company" value="{{ $contact->company }}" class="form-control form-control-sm">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted mb-2">{{ __('Unit details (landlord)') }}</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Unit No') }}</label>
                            <input type="text" name="unit_no" value="{{ $contact->unit_no }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Building') }}</label>
                            <input type="text" name="building" value="{{ $contact->building }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Community') }}</label>
                            <input type="text" name="community" value="{{ $contact->community }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Category') }}</label>
                            <select name="property_category" class="form-select form-select-sm">
                                <option value="">—</option>
                                @foreach(\App\Models\Property::CATEGORIES as $k => $v)
                                    <option value="{{ $k }}" {{ $k === $contact->property_category ? 'selected' : '' }}>{{ __($v) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">{{ __('Beds') }}</label>
                            <input type="number" name="bedrooms" value="{{ $contact->bedrooms }}" min="0" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Asking Rent') }}</label>
                            <input type="number" name="rent_price" value="{{ $contact->rent_price }}" min="0" step="0.01" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Unit status') }}</label>
                            <input type="text" name="unit_status" value="{{ $contact->unit_status }}" class="form-control form-control-sm" placeholder="{{ __('available / leased / sold') }}">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">{{ __('Address') }}</label>
                            <input type="text" name="address" value="{{ $contact->address }}" class="form-control form-control-sm">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted mb-2">{{ __('Investor interest') }}</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Budget') }}</label>
                            <input type="number" name="budget" value="{{ $contact->budget }}" min="0" step="0.01" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Preferred type') }}</label>
                            <input type="text" name="preferred_type" value="{{ $contact->preferred_type }}" class="form-control form-control-sm" placeholder="{{ __('Apartment, Villa…') }}">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">{{ __('Requirements / Notes') }}</label>
                            <textarea name="requirements" rows="2" class="form-control form-control-sm">{{ $contact->requirements }}</textarea>
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted mb-2">{{ __('General note') }}</h6>
                    <div class="mb-3">
                        <textarea name="notes" rows="2" class="form-control form-control-sm">{{ $contact->notes }}</textarea>
                    </div>

                    <div class="d-flex justify-content-between">
                        <form method="POST" action="{{ route('market.destroy', $contact) }}" onsubmit="return confirm('{{ __('Delete this contact?') }}')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm">{{ __('Delete') }}</button>
                        </form>
                        <button class="btn btn-primary ms-auto">{{ __('Save changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Right: convert actions --}}
    <div class="col-lg-5">

        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Convert contact') }}</h3>
            </div>
            <div class="card-body">
                @if($contact->status === 'converted')
                    <div class="alert alert-success mb-0">
                        {{ __('Already converted to') }} {{ $contact->converted_type === 'property' ? __('a unit') : __('a lead') }}.
                        @if($contact->converted_type === 'property')
                            <a href="{{ route('inventory.show', $contact->converted_id) }}">{{ __('View unit') }}</a>
                        @endif
                    </div>
                @else
                    {{-- Convert to Property --}}
                    @if($contact->type === 'landlord' && $contact->has_unit_details)
                        <form method="POST" action="{{ route('market.convert-property', $contact) }}">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label">{{ __('Calling agent (will own the unit)') }} <span class="text-danger">*</span></label>
                                <select name="agent_id" class="form-select" required>
                                    @foreach($agents as $id => $name)
                                        <option value="{{ $id }}" {{ $id === ($contact->called_by ?? auth()->id()) ? 'selected' : '' }}>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">{{ __('Availability') }}</label>
                                <select name="availability" class="form-select">
                                    <option value="ready_to_list">{{ __('Ready to List') }}</option>
                                    <option value="listed">{{ __('Listed') }}</option>
                                    <option value="draft">{{ __('Draft') }}</option>
                                </select>
                            </div>
                            <div class="mb-2">{{ __('The unit is added to Inventory under the agent above. The owner/landlord is kept as a linked lead.') }}</div>
                            <button type="submit" class="btn btn-warning w-100" onclick="return confirm('{{ __('Create inventory unit from this landlord contact?') }}')">{{ __('Convert to unit (Leasing)') }}</button>
                        </form>
                        <hr>
                    @endif

                    {{-- Convert to Lead --}}
                    <form method="POST" action="{{ route('market.convert-lead', $contact) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label">{{ __('Assign to agent') }} <span class="text-danger">*</span></label>
                            <select name="agent_id" class="form-select" required>
                                @foreach($agents as $id => $name)
                                    <option value="{{ $id }}" {{ $id === ($contact->called_by ?? auth()->id()) ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">{{ __('Creates a sales lead (deal_type = Sales) so you can edit and adjust anything that is not the same.') }}</div>
                        <button type="submit" class="btn btn-primary w-100" onclick="return confirm('{{ __('Create a sales lead from this contact?') }}')">{{ __('Convert to lead (Sales)') }}</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Call log') }}</h3></div>
            <div class="card-body p-0">
                @forelse($contact->call_notes ? ['last' => $contact->call_notes] : [] as $note)
                    <div class="p-3">
                        <div class="small text-muted">{{ $contact->last_called_at?->format('j M Y, g:i a') }} · {{ $contact->caller?->name }}</div>
                        <div class="mt-1">{{ $note }}</div>
                    </div>
                @empty
                    <div class="p-3 text-muted">{{ __('No call notes yet.') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@endsection