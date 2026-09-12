@extends('layouts.app')

@section('title', __('Add Availability Source'))
@section('page-title', __('Add Availability Source'))

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Source details') }}</h3></div>
            <div class="card-body">
                <form method="POST" action="{{ route('availability-sources.store') }}">
                    @csrf
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Company / source name') }} <span class="text-danger">*</span></label>
                            <input type="text" name="name" required class="form-control" value="{{ old('name') }}" placeholder="{{ __('AMS Properties') }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Contact info') }}</label>
                            <input type="text" name="contact_info" class="form-control" value="{{ old('contact_info') }}" placeholder="{{ __('email / phone / manager name') }}">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">{{ __('Public availability link (optional)') }}</label>
                            <input type="url" name="url" class="form-control" value="{{ old('url') }}" placeholder="{{ __('https://rdk.ae/Listing/data.json') }}">
                            <div class="form-hint">{{ __('If the PM publishes their availability online (like RDK), paste the link and a "Sync now" button will appear. Mapping is configured automatically for the RDK format.') }}</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default building') }}</label>
                            <input type="text" name="default_building" class="form-control" value="{{ old('default_building') }}" placeholder="{{ __('used when sheets omit building') }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default category') }}</label>
                            <select name="default_category" class="form-select">
                                <option value="">{{ __('— detect from text —') }}</option>
                                @foreach(\App\Models\Property::CATEGORIES as $key => $label)
                                    <option value="{{ $key }}" {{ old('default_category') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default city') }}</label>
                            <input type="text" name="default_city" class="form-control" value="{{ old('default_city', 'Abu Dhabi') }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default deposit (AED)') }}</label>
                            <input type="number" name="default_deposit" min="0" step="0.01" class="form-control" value="{{ old('default_deposit') }}" placeholder="{{ __('applied when sheet has no deposit column') }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default admin fee (AED)') }}</label>
                            <input type="number" name="default_admin_fee" min="0" step="0.01" class="form-control" value="{{ old('default_admin_fee') }}" placeholder="{{ __('e.g. 1050') }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default Tawtheeq fee (AED)') }}</label>
                            <input type="number" name="default_tawtheeq_fee" min="0" step="0.01" class="form-control" value="{{ old('default_tawtheeq_fee') }}" placeholder="{{ __('e.g. 150') }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('When a tracked unit leaves the list') }}</label>
                            <select name="missing_status" class="form-select">
                                @php $missingDefault = old('missing_status', old('url') ? 'unlisted' : 'leased'); @endphp
                                <option value="leased" {{ $missingDefault === 'leased' ? 'selected' : '' }}>{{ __('Mark as leased (rented)') }}</option>
                                <option value="unlisted" {{ $missingDefault === 'unlisted' ? 'selected' : '' }}>{{ __('Mark as unlisted (off the market)') }}</option>
                            </select>
                            <div class="form-hint">{{ __('Sheets: dropped = rented. Published links (e.g. RDK): hidden = off the market, use Unlisted.') }}</div>
                        </div>
                    </div>
                    <div class="form-hint mb-3">{{ __('You define the column mapping on the next screen. After that, importing a refreshed list is a one-click job that reuses the same layout.') }}</div>
                    <button class="btn btn-primary">{{ __('Save & Configure Mapping') }}</button>
                    <a href="{{ route('availability-sources.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection