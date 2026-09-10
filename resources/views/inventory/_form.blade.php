<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ isset($editMode) && $editMode ? __('Edit Unit') : __('Add Unit') }}</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ isset($editMode) && $editMode ? route('inventory.update', $property) : route('inventory.store') }}" enctype="multipart/form-data">
            @csrf
            @if(isset($editMode) && $editMode) @method('PUT') @endif

            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">{{ __('Listing') }}</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <label class="form-label required">{{ __('Intent') }}</label>
                            <select name="intent" class="form-select @error('intent') is-invalid @enderror" required>
                                <option value="">{{ __('Select...') }}</option>
                                @foreach(\App\Models\Property::INTENTS as $key => $label)
                                    <option value="{{ $key }}" {{ old('intent', $property->intent) === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            @error('intent') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">{{ __('Stock') }}</label>
                            <select name="market_class" class="form-select @error('market_class') is-invalid @enderror" required>
                                <option value="">{{ __('Select...') }}</option>
                                @foreach(\App\Models\Property::MARKET_CLASSES as $key => $label)
                                    <option value="{{ $key }}" {{ old('market_class', $property->market_class) === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            @error('market_class') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">{{ __('Category') }}</label>
                            <select name="property_category" class="form-select @error('property_category') is-invalid @enderror" required>
                                <option value="">{{ __('Select...') }}</option>
                                @foreach(\App\Models\Property::CATEGORIES as $key => $label)
                                    <option value="{{ $key }}" {{ old('property_category', $property->property_category) === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            @error('property_category') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Furnishing') }}</label>
                            <select name="furnishing" class="form-select">
                                <option value="">{{ __('Select...') }}</option>
                                @foreach(\App\Models\Property::FURNISHING as $key => $label)
                                    <option value="{{ $key }}" {{ old('furnishing', $property->furnishing) === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Bedrooms') }}</label>
                            <input type="number" name="bedrooms" min="0" class="form-control @error('bedrooms') is-invalid @enderror" value="{{ old('bedrooms', $property->bedrooms) }}">
                            @error('bedrooms') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Bathrooms') }}</label>
                            <input type="number" name="bathrooms" min="0" class="form-control @error('bathrooms') is-invalid @enderror" value="{{ old('bathrooms', $property->bathrooms) }}">
                            @error('bathrooms') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Size (sqft)') }}</label>
                            <input type="number" name="square_footage" min="0" step="0.01" class="form-control @error('square_footage') is-invalid @enderror" value="{{ old('square_footage', $property->square_footage) }}">
                            @error('square_footage') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Parking') }}</label>
                            <input type="number" name="parking" min="0" class="form-control @error('parking') is-invalid @enderror" value="{{ old('parking', $property->parking) }}">
                            @error('parking') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">{{ __('Pricing') }}</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Rent price (AED)') }}</label>
                            <input type="number" name="rent_price" min="0" step="0.01" class="form-control @error('rent_price') is-invalid @enderror" value="{{ old('rent_price', $property->rent_price) }}">
                            @error('rent_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Deposit (AED)') }}</label>
                            <input type="number" name="deposit_amount" min="0" step="0.01" class="form-control @error('deposit_amount') is-invalid @enderror" value="{{ old('deposit_amount', $property->deposit_amount) }}" placeholder="{{ __('5% of rent usually') }}">
                            @error('deposit_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Admin fee (AED)') }}</label>
                            <input type="number" name="admin_fee" min="0" step="0.01" class="form-control @error('admin_fee') is-invalid @enderror" value="{{ old('admin_fee', $property->admin_fee) }}">
                            @error('admin_fee') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Tawtheeq fee (AED)') }}</label>
                            <input type="number" name="tawtheeq_fee" min="0" step="0.01" class="form-control @error('tawtheeq_fee') is-invalid @enderror" value="{{ old('tawtheeq_fee', $property->tawtheeq_fee) }}">
                            @error('tawtheeq_fee') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Rent period') }}</label>
                            <select name="rent_period" class="form-select">
                                @foreach(\App\Models\Property::RENT_PERIODS as $key => $label)
                                    <option value="{{ $key }}" {{ old('rent_period', $property->rent_period ?? 'yearly') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Sale price (AED)') }}</label>
                            <input type="number" name="list_price" min="0" step="0.01" class="form-control @error('list_price') is-invalid @enderror" value="{{ old('list_price', $property->list_price) }}">
                            @error('list_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Service charge') }}</label>
                            <input type="number" name="service_charge" min="0" step="0.01" class="form-control" value="{{ old('service_charge', $property->service_charge) }}">
                        </div>
                    </div>
                    <div class="form-hint mt-2">{{ __('Enter rent price, sale price, or both depending on intent.') }}</div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">{{ __('Location & Title') }}</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Address') }}</label>
                            <input type="text" name="address" class="form-control @error('address') is-invalid @enderror" value="{{ old('address', $property->address) }}" placeholder="{{ __('Street address (or community is enough)') }}">
                            @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('City') }}</label>
                            <input type="text" name="city" class="form-control" value="{{ old('city', $property->city) }}" placeholder="{{ __('Dubai') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Community') }}</label>
                            <input type="text" name="community" class="form-control" value="{{ old('community', $property->community) }}" placeholder="{{ __('Dubai Marina') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Sub-community') }}</label>
                            <input type="text" name="sub_community" class="form-control" value="{{ old('sub_community', $property->sub_community) }}" placeholder="{{ __('Marina Heights') }}">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Building No') }}</label>
                            <input type="text" name="building_no" class="form-control" value="{{ old('building_no', $property->building_no) }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Unit No') }}</label>
                            <input type="text" name="unit_no" class="form-control" value="{{ old('unit_no', $property->unit_no) }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Floor No') }}</label>
                            <input type="text" name="floor_no" class="form-control" value="{{ old('floor_no', $property->floor_no) }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Plot No') }}</label>
                            <input type="text" name="plot_no" class="form-control" value="{{ old('plot_no', $property->plot_no) }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Title Deed No') }}</label>
                            <input type="text" name="title_deed_no" class="form-control" value="{{ old('title_deed_no', $property->title_deed_no) }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('RERA Permit No') }}</label>
                            <input type="text" name="rera_permit_no" class="form-control @error('rera_permit_no') is-invalid @enderror" value="{{ old('rera_permit_no', $property->rera_permit_no) }}" placeholder="{{ __('Required by portals') }}">
                            @error('rera_permit_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Developer (off-plan)') }}</label>
                            <input type="text" name="developer_name" class="form-control" value="{{ old('developer_name', $property->developer_name) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Handover date') }}</label>
                            <input type="date" name="handover_date" class="form-control" value="{{ old('handover_date', $property->handover_date?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Virtual tour URL') }}</label>
                            <input type="url" name="virtual_tour_url" class="form-control" value="{{ old('virtual_tour_url', $property->virtual_tour_url) }}" placeholder="https://...">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">{{ __('Owner') }}</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Owner name') }}</label>
                            <input type="text" name="owner_name" class="form-control" value="{{ old('owner_name', $property->owner_name) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Owner phone') }}</label>
                            <input type="text" name="owner_phone" class="form-control" value="{{ old('owner_phone', $property->owner_phone) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Owner email') }}</label>
                            <input type="email" name="owner_email" class="form-control" value="{{ old('owner_email', $property->owner_email) }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">{{ __('Marketing') }}</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Listing title') }}</label>
                            <input type="text" name="marketing_title" class="form-control" value="{{ old('marketing_title', $property->marketing_title) }}" placeholder="{{ __('2BR Apartment in Dubai Marina') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Notes (internal)') }}</label>
                            <input type="text" name="notes" class="form-control" value="{{ old('notes', $property->notes) }}">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Description') }}</label>
                            <textarea name="marketing_description" rows="5" class="form-control @error('marketing_description') is-invalid @enderror" placeholder="{{ __('For the portals...') }}">{{ old('marketing_description', $property->marketing_description) }}</textarea>
                            @error('marketing_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Photos') }}</label>
                            <input type="file" name="photos[]" class="form-control" multiple accept="image/*">
                            <div class="form-hint mt-1">{{ __('Add photos. You can add more after saving from the unit page.') }}</div>

                            <label class="form-label mt-3">{{ __('Floor plan') }}</label>
                            <input type="file" name="floor_plan" class="form-control" accept="image/*">

                            <label class="form-label mt-3">{{ __('Or paste external photo URLs (CDN/portal)') }}</label>
                            <textarea name="external_photo_urls" rows="2" class="form-control" placeholder="{{ __('One URL per line') }}">{{ old('external_photo_urls') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">{{ __('Status') }}</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label required">{{ __('Availability') }}</label>
                            <select name="availability" class="form-select @error('availability') is-invalid @enderror" required>
                                @foreach(\App\Models\Property::AVAILABILITIES as $key => $label)
                                    <option value="{{ $key }}" {{ old('availability', $property->availability ?? 'draft') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            @error('availability') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Assigned agent') }}</label>
                            <select name="assigned_agent_id" class="form-select">
                                <option value="">{{ __('Unassigned') }}</option>
                                @foreach($agents as $agentId => $agentName)
                                    <option value="{{ $agentId }}" {{ old('assigned_agent_id', $property->assigned_agent_id) == $agentId ? 'selected' : '' }}>{{ $agentName }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 align-items-center">
                <button type="submit" class="btn btn-primary">{{ __('Save Unit') }}</button>
                <a href="{{ url()->previous() }}" class="btn btn-link">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</div>