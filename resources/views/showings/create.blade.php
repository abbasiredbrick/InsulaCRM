@extends('layouts.app')

@section('title', __('Schedule Viewing'))
@section('page-title', __('Schedule Viewing'))

@section('content')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Schedule a Property Viewing') }}</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('showings.store') }}">
            @csrf

<div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Client') }}</label>
                    <x-searchable-select
                        name="lead_id"
                        :options="$leadOptions"
                        :selected="old('lead_id', $preselectedLeadId ?? '')"
                        :invalid="$errors->has('lead_id')"
                        :placeholder="__('Select client (optional)...')"
                        :search-placeholder="__('Type name to search...')"
                    />
                    @error('lead_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label required">{{ __('Property') }}</label>
                    <x-searchable-select
                        name="property_id"
                        :options="$propertyOptions"
                        :selected="old('property_id', '')"
                        :invalid="$errors->has('property_id')"
                        required
                        :placeholder="__('Select property...')"
                        :search-placeholder="__('Type title, community, unit...')"
                    />
                    @error('property_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label required">{{ __('Date') }}</label>
                    <input type="date" name="showing_date" class="form-control @error('showing_date') is-invalid @enderror" value="{{ old('showing_date', date('Y-m-d')) }}" required>
                    @error('showing_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label required">{{ __('Time') }}</label>
                    <input type="time" name="showing_time" class="form-control @error('showing_time') is-invalid @enderror" value="{{ old('showing_time', '10:00') }}" required>
                    @error('showing_time') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Duration (minutes)') }}</label>
                    <input type="number" name="duration_minutes" class="form-control @error('duration_minutes') is-invalid @enderror" value="{{ old('duration_minutes', 30) }}" min="15" max="480">
                    @error('duration_minutes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Agent') }}</label>
                    <select name="agent_id" class="form-select @error('agent_id') is-invalid @enderror">
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" {{ old('agent_id', auth()->id()) == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                    @error('agent_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Listing Agent Name') }}</label>
                    <input type="text" name="listing_agent_name" class="form-control" value="{{ old('listing_agent_name') }}" placeholder="{{ __('Other listing agent (if applicable)') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Listing Agent Phone') }}</label>
                    <input type="text" name="listing_agent_phone" class="form-control" value="{{ old('listing_agent_phone') }}">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('Notes') }}</label>
                <textarea name="notes" class="form-control" rows="3">{{ old('notes') }}</textarea>
            </div>

            <div class="row mb-3">
                <div class="col-md-3">
                    <x-reminder-minutes :selected="old('reminder_minutes')" />
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ __('Schedule Viewing') }}</button>
                <a href="{{ route('showings.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</div>
@endsection
