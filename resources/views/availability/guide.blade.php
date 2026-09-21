@extends('layouts.app')

@section('title', __('Availability Import Guide'))
@section('page-title', __('Availability Import Guide'))

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <h2 class="mb-1">{{ __('How to prepare an availability list for import') }}</h2>
                        <p class="text-muted mb-0">{{ __('Property-management companies (AMS, Relevate, Bloom, RDK…) share their unit lists as Excel, CSV, PDF or email. Prepare the file once in the layout below, map it to your source, then re-import refreshed sheets in one click — units update in place and units that leave the list are reconciled automatically.') }}</p>
                    </div>
                    <div class="text-nowrap">
                        <a href="{{ route('availability-sources.template') }}" class="btn btn-primary">{{ __('Download blank template (CSV)') }}</a>
                        <a href="{{ route('availability-sources.index') }}" class="btn btn-link">{{ __('Back to Inventory Sources') }}</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">{{ __('1 · Prepare the file') }}</h3></div>
            <div class="card-body">
                <ul class="mb-3">
                    <li>{{ __('One unit per row. The first row may be a header row with column names — the importer detects it automatically.') }}</li>
                    <li>{{ __('Keep unit numbers, rent and status in their own columns. Do not merge cells or add totals/summary rows.') }}</li>
                    <li>{{ __('Rent and fees must be numbers (125000, not "AED 125k"). Deposit is optional if the source has a default deposit rule.') }}</li>
                    <li>{{ __('If a sheet mixes buildings, include a Building column, or set a Default building on the source and import one building per file.') }}</li>
                    <li>{{ __('Save the file as .csv (UTF-8), or upload the original .xlsx / paste the text straight from the email.') }}</li>
                </ul>

                <h4 class="mb-2">{{ __('Standard columns') }}</h4>
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter">
                        <thead>
                            <tr><th>{{ __('Column header') }}</th><th>{{ __('Example') }}</th><th>{{ __('Maps to') }}</th><th>{{ __('Notes') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Unit No</td><td>1201</td><td><code>unit_no</code></td><td>{{ __('Required — identifies the unit across re-imports.') }}</td></tr>
                            <tr><td>Building</td><td>Burj Al Shams</td><td><code>building</code></td><td>{{ __('Optional if the source has a default building.') }}</td></tr>
                            <tr><td>Community</td><td>Al Reem Island</td><td><code>community</code></td><td></td></tr>
                            <tr><td>Unit Type</td><td>2 BHK, 3 BHK + M</td><td><code>features</code></td><td>{{ __('Bedrooms are read from here too.') }}</td></tr>
                            <tr><td>Area (Sqft)</td><td>1121</td><td><code>square_footage</code></td><td>{{ __('Use Area (Sqm) for square metres.') }}</td></tr>
                            <tr><td>Bedrooms</td><td>2</td><td><code>bedrooms</code></td><td></td></tr>
                            <tr><td>Rent</td><td>100000</td><td><code>rent</code></td><td>{{ __('Annual rent in AED.') }}</td></tr>
                            <tr><td>Deposit</td><td>5000</td><td><code>deposit</code></td><td>{{ __('Leave blank to use the source default.') }}</td></tr>
                            <tr><td>Admin Fee</td><td>1050</td><td><code>admin_fee</code></td><td></td></tr>
                            <tr><td>Tawtheeq Fee</td><td>150</td><td><code>tawtheeq</code></td><td></td></tr>
                            <tr><td>Status</td><td>Available for viewing</td><td><code>status</code></td><td>{{ __('See status words below.') }}</td></tr>
                            <tr><td>Balcony</td><td>Yes / No</td><td><code>balcony</code></td><td></td></tr>
                            <tr><td>View</td><td>Sea View</td><td><code>view</code></td><td></td></tr>
                            <tr><td>Available From</td><td>26 Sep 2026</td><td><code>available_from</code></td><td>{{ __('Date an Upcoming unit becomes available.') }}</td></tr>
                            <tr><td>Parking</td><td>1</td><td><code>parking</code></td><td></td></tr>
                            <tr><td>Amenities</td><td>Gym, Pool</td><td><code>amenities</code></td><td></td></tr>
                            <tr><td>Remarks</td><td>Commission 5%</td><td><code>remarks</code></td><td>{{ __('Commission notes are captured from here.') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small mb-0">{{ __('Column names do not have to match exactly — common synonyms are detected. Anything you map explicitly on the source always wins.') }}</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">{{ __('2 · Status words') }}</h3></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter">
                        <thead><tr><th>{{ __('Text in the sheet') }}</th><th>{{ __('Inventory status') }}</th></tr></thead>
                        <tbody>
                            <tr><td>Available, Available for viewing, Vacant, Open for viewing</td><td><span class="badge bg-azure-lt">{{ __('Ready to List') }}</span></td></tr>
                            <tr><td>Upcoming, Up-coming</td><td><span class="badge bg-cyan-lt">{{ __('Upcoming') }}</span> {{ __('with the date from Available From') }}</td></tr>
                            <tr><td>Under offer, Reserved, Booked, On hold</td><td><span class="badge bg-orange-lt">{{ __('Reserved') }}</span></td></tr>
                            <tr><td>Rented, Leased, Let</td><td><span class="badge bg-blue-lt">{{ __('Leased') }}</span></td></tr>
                            <tr><td>Sold</td><td><span class="badge bg-purple-lt">{{ __('Sold') }}</span></td></tr>
                            <tr><td>Withdrawn, Off market, Unlisted</td><td><span class="badge bg-dark-lt">{{ __('Unlisted') }}</span></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small mb-0">{{ __('You can override any of these on the source\'s mapping screen using lines like "Under Offer => reserved".') }}</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">{{ __('3 · Dates, fees and deposit') }}</h3></div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>{{ __('Dates are read in many formats: 14 Sep 2026, Sep 14, 2026, 14/09/2026, 14.09.2026, 2026-09-14.') }}</li>
                    <li>{{ __('The word "Available" in a date column means the unit is ready now, so no date is stored.') }}</li>
                    <li>{{ __('Admin fee and Tawtheeq fee fall back to the source defaults when the sheet has no column for them.') }}</li>
                    <li>{{ __('A deposit can be a fixed default, or a formula: "the higher of AED 5,000 or 5% of the annual rent". Set the deposit percentage and minimum on the source and it is computed per unit.') }}</li>
                </ul>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">{{ __('4 · From Excel to CSV') }}</h3></div>
            <div class="card-body">
                <ol class="mb-0">
                    <li>{{ __('Open the PM sheet in Excel or Google Sheets.') }}</li>
                    <li>{{ __('Keep only the header row and the unit rows — remove logos, notes and totals.') }}</li>
                    <li>{{ __('Excel: File → Save As → CSV UTF-8 (Comma delimited). Google Sheets: File → Download → CSV.') }}</li>
                    <li>{{ __('Upload it on the source\'s Import screen (or paste the text). Preview the parsed rows, then run the import.') }}</li>
                </ol>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">{{ __('5 · Importing the refreshed list') }}</h3></div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>{{ __('"Quick Re-import" reuses the saved mapping and runs immediately. Units that already exist are updated in place — nothing is duplicated.') }}</li>
                    <li>{{ __('Units that leave the sheet are handled by the source rule: "mark as leased" for rented sheets, or "mark as unlisted" for published links.') }}</li>
                    <li>{{ __('A listed unit that disappears from a rented sheet is queued for a decision instead of being unlisted, so a paid listing permit is not wasted.') }}</li>
                    <li>{{ __('If a new sheet has different columns, the importer rebuilds the mapping from the header row automatically.') }}</li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0">{{ __('Sample files') }}</h3></div>
            <div class="card-body">
                <p class="text-muted">{{ __('Download a spreadsheet with the expected columns and two example rows. Open it in Excel, replace the examples with the PM\'s data, save as CSV and import.') }}</p>
                <a href="{{ route('availability-sources.template') }}" class="btn btn-outline-primary btn-sm">{{ __('Blank template') }}</a>
                <span class="text-muted small ms-2">{{ __('Each source also has a "Sample CSV" download that matches its exact saved mapping.') }}</span>
            </div>
        </div>
    </div>
</div>
@endsection
