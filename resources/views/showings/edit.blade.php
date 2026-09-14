@extends('layouts.app')

@section('title', __('Edit Viewing'))
@section('page-title', __('Edit Viewing'))

@section('content')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Edit Viewing') }}</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('showings.update', $showing) }}">
            @csrf @method('PUT')

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label required">{{ __('Property') }}</label>
                    <x-searchable-select
                        name="property_id"
                        :options="$propertyOptions"
                        :selected="old('property_id', $showing->property_id)"
                        :invalid="$errors->has('property_id')"
                        required
                        :placeholder="__('Select property...')"
                        :search-placeholder="__('Type title, community, unit...')"
                    />
                    @error('property_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Client') }}</label>
                    <x-searchable-select
                        name="lead_id"
                        :options="$leadOptions"
                        :selected="old('lead_id', $showing->lead_id)"
                        :invalid="$errors->has('lead_id')"
                        :placeholder="__('Select client (optional)...')"
                        :search-placeholder="__('Type name to search...')"
                    />
                    @error('lead_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label required">{{ __('Date') }}</label>
                    <input type="date" name="showing_date" class="form-control @error('showing_date') is-invalid @enderror" value="{{ old('showing_date', $showing->showing_date->format('Y-m-d')) }}" required>
                    @error('showing_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label required">{{ __('Time') }}</label>
                    <input type="time" name="showing_time" class="form-control @error('showing_time') is-invalid @enderror" value="{{ old('showing_time', $showing->showing_time) }}" required>
                    @error('showing_time') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Duration (minutes)') }}</label>
                    <input type="number" name="duration_minutes" class="form-control @error('duration_minutes') is-invalid @enderror" value="{{ old('duration_minutes', $showing->duration_minutes) }}" min="15" max="480">
                    @error('duration_minutes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Agent') }}</label>
                    <select name="agent_id" class="form-select @error('agent_id') is-invalid @enderror">
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" {{ old('agent_id', $showing->agent_id) == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                    @error('agent_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Listing Agent Name') }}</label>
                    <input type="text" name="listing_agent_name" class="form-control" value="{{ old('listing_agent_name', $showing->listing_agent_name) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Listing Agent Phone') }}</label>
                    <input type="text" name="listing_agent_phone" class="form-control" value="{{ old('listing_agent_phone', $showing->listing_agent_phone) }}">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('Notes') }}</label>
                <textarea name="notes" class="form-control" rows="3">{{ old('notes', $showing->notes) }}</textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ __('Update Viewing') }}</button>
                <a href="{{ route('showings.show', $showing) }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</div>
@endsection
