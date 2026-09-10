@php
    $colors = ['not_listed' => 'secondary', 'live' => 'green', 'removed' => 'danger'];
@endphp
<span class="badge bg-{{ $colors[$status] ?? 'secondary' }}">{{ __(\App\Models\Property::PORTAL_STATUSES[$status] ?? $status) }}</span>