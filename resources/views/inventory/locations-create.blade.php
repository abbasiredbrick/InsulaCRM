@extends('layouts.app')

@section('title', __('New Location'))
@section('page-title', __('New Location'))

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title mb-0">{{ __('Create a building') }}</h3>
                <a href="{{ $return }}" class="btn btn-sm btn-outline-secondary">{{ __('Back') }}</a>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    {{ __('A building sits under a community, and a community sits under a city. Create the chain below — the building is then selected on your unit.') }}
                </p>

                <form method="POST" action="{{ route('inventory.locations-store') }}">
                    @csrf
                    <input type="hidden" name="return" value="{{ old('return', $return) }}">

                    <div class="mb-3">
                        <label class="form-label required">{{ __('City') }}</label>
                        <select name="city" class="form-select @error('city') is-invalid @enderror" required>
                            <option value="">{{ __('Select a city...') }}</option>
                            @foreach($cities as $city)
                                <option value="{{ $city }}" {{ old('city') === $city ? 'selected' : '' }}>{{ $city }}</option>
                            @endforeach
                        </select>
                        @error('city') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label required">{{ __('Community') }}</label>
                        <input type="text" name="community" class="form-control @error('community') is-invalid @enderror"
                            value="{{ old('community') }}" placeholder="{{ __('e.g. Al Reem Island') }}" required>
                        @error('community') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-hint">{{ __('If the community already exists under this city, it is reused.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label required">{{ __('Sub-community / Building') }}</label>
                        <input type="text" name="sub_community" class="form-control @error('sub_community') is-invalid @enderror"
                            value="{{ old('sub_community', $name) }}" placeholder="{{ __('e.g. Sky Tower') }}" required>
                        @error('sub_community') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ __('Create building') }}</button>
                        <a href="{{ $return }}" class="btn btn-link">{{ __('Cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
