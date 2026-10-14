@extends('layouts.app')
@section('title', __('Import from Google Drive'))

@section('page-title', __('Import from Google Drive'))

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

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Choose a spreadsheet') }}</h3>
        <div class="card-actions">
            @if($connectedEmail)
                <span class="badge bg-green-lt">{{ $connectedEmail }}</span>
            @endif
            <a href="{{ route('market.create') }}" class="btn btn-outline-secondary btn-sm ms-2">{{ __('Upload a file instead') }}</a>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">{{ __('Sheets you own or have opened are listed below. Google Sheets are exported to Excel on import; .xlsx, .csv and .txt files are imported directly.') }}</p>

        <form method="POST" action="{{ route('market.drive.import') }}">
            @csrf

            <div class="table-responsive mb-3">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th class="w-1"></th>
                            <th>{{ __('File') }}</th>
                            <th>{{ __('Kind') }}</th>
                            <th>{{ __('Modified') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($files as $file)
                            @php
                                $kind = $file['mimeType'] === 'application/vnd.google-apps.spreadsheet' ? __('Google Sheet')
                                    : (str_contains($file['mimeType'], 'spreadsheetml') ? __('Excel') : __('CSV / Text'));
                            @endphp
                            <tr>
                                <td>
                                    <input class="form-check-input" type="radio" name="file_id" value="{{ $file['id'] }}" data-name="{{ $file['name'] }}" required>
                                </td>
                                <td>{{ $file['name'] }}</td>
                                <td><span class="badge bg-primary-lt">{{ $kind }}</span></td>
                                <td class="text-muted small">{{ $file['modifiedTime'] ? \Carbon\Carbon::parse($file['modifiedTime'])->format('j M Y') : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">{{ __('No spreadsheets found on this Drive account.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">{{ __('List name') }} <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" id="listName" placeholder="{{ __('e.g. Marina Tower Owners Sep 2026') }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('List type') }} <span class="text-danger">*</span></label>
                    <select name="type" class="form-select" required>
                        @foreach($importTypes as $key => $label)
                            <option value="{{ $key }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary mt-3" id="importBtn" disabled>
                {{ __('Import selected file') }}
            </button>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('change', function (e) {
    var radio = e.target.closest('input[type="radio"][name="file_id"]');
    if (!radio) return;
    document.getElementById('listName').value = radio.dataset.name || '';
    document.getElementById('importBtn').disabled = false;
});
</script>
@endpush

@endsection
