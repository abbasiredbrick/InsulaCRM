@php
    $p = $provider ?? null;
@endphp

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Service Provider Registration') }}</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="row g-3">
            @csrf

            <div class="col-12">
                <hr class="my-1">
                <h4 class="mb-2">{{ __('Company details') }}</h4>
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Category') }} *</label>
                <select name="category" class="form-select @error('category') is-invalid @enderror" required>
                    <option value="">{{ __('Select category') }}</option>
                    @foreach(\App\Models\ServiceProvider::CATEGORIES as $key => $label)
                        <option value="{{ $key }}" @selected(old('category', $p->category ?? '') === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
                @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Company name') }} *</label>
                <input type="text" name="company_name" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name', $p->company_name ?? '') }}" maxlength="120" required>
                @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Trade license number') }} *</label>
                <input type="text" name="trade_license_number" class="form-control @error('trade_license_number') is-invalid @enderror" value="{{ old('trade_license_number', $p->trade_license_number ?? '') }}" maxlength="60" required>
                @error('trade_license_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <label class="form-label">{{ __('Services offered') }}</label>
                <textarea name="services_offered" class="form-control @error('services_offered') is-invalid @enderror" rows="2" maxlength="1000">{{ old('services_offered', $p->services_offered ?? '') }}</textarea>
                <small class="form-hint">{{ __('Short description of the services your company provides.') }}</small>
                @error('services_offered')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <hr class="my-1">
                <h4 class="mb-2">{{ __('Representative') }}</h4>
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Full name') }} *</label>
                <input type="text" name="representative_name" class="form-control @error('representative_name') is-invalid @enderror" value="{{ old('representative_name', $p->representative_name ?? '') }}" maxlength="120" required>
                @error('representative_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Emirates ID') }} *</label>
                <input type="text" name="representative_emirates_id" class="form-control @error('representative_emirates_id') is-invalid @enderror" value="{{ old('representative_emirates_id', $p->representative_emirates_id ?? '') }}" placeholder="784-1998-1234567-1" maxlength="30" required>
                @error('representative_emirates_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <hr class="my-1">
                <h4 class="mb-2">{{ __('Contact details') }}</h4>
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Mobile') }} *</label>
                <input type="tel" name="mobile" class="form-control @error('mobile') is-invalid @enderror" value="{{ old('mobile', $p->mobile ?? '') }}" maxlength="30" required>
                @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Email') }} *</label>
                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $p->email ?? '') }}" maxlength="190" required>
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('City / Area') }}</label>
                <input type="text" name="city" class="form-control @error('city') is-invalid @enderror" value="{{ old('city', $p->city ?? '') }}" maxlength="120">
                @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Website') }}</label>
                <input type="url" name="website" class="form-control @error('website') is-invalid @enderror" value="{{ old('website', $p->website ?? '') }}" maxlength="255">
                @error('website')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <hr class="my-1">
                <h4 class="mb-2">{{ __('Documents') }}</h4>
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Trade license copy') }} *</label>
                <input type="file" name="trade_license_document" class="form-control @error('trade_license_document') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png" required>
                <small class="form-hint">{{ __('PDF or image, up to 5 MB.') }}</small>
                @error('trade_license_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">{{ __('Representative Emirates ID copy') }} *</label>
                <input type="file" name="emirates_id_document" class="form-control @error('emirates_id_document') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png" required>
                <small class="form-hint">{{ __('PDF or image, up to 5 MB.') }}</small>
                @error('emirates_id_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <label class="form-label">{{ __('Additional documents') }}</label>
                <input type="file" name="additional_documents[]" class="form-control @error('additional_documents') is-invalid @enderror" multiple>
                <small class="form-hint">{{ __('Optional — certificates, VAT registration, insurance, etc.') }}</small>
                @error('additional_documents')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('additional_documents.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12 d-flex align-items-center gap-2">
                <button type="submit" class="btn btn-primary">{{ __('Submit application') }}</button>
                <span class="text-muted small">{{ __('* required') }}</span>
            </div>
        </form>
    </div>
</div>