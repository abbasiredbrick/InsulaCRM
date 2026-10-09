@extends('layouts.app')

@section('title', __('Search Results'))
@section('page-title', __('Search Results for') . ' "' . e($query) . '"')

@section('content')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __(':count results found', ['count' => $results->count()]) }}</h3>
        @if($results->where('type', 'property')->isNotEmpty() && auth()->user()->tenant && auth()->user()->tenant->slug)
        <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-share-results
                data-url="{{ route('share.inventory', auth()->user()->tenant->slug) }}?search={{ urlencode($query) }}&agent={{ auth()->user()->agent_code ?? '' }}"
                data-label="{{ __('Available units matching') }} &quot;{{ $query }}&quot;">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M8.7 10.7l6.6 -3.4"/><path d="M8.7 13.3l6.6 3.4"/></svg>
            {{ __('Share availability') }}
        </button>
        @endif
    </div>
    @if($results->count())
    <div class="list-group list-group-flush">
        @foreach($results as $result)
        @php
            $typeColors = ['lead' => 'bg-blue', 'deal' => 'bg-purple', 'buyer' => 'bg-green', 'property' => 'bg-orange'];
            $typeIcons = [
                'lead' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="7" r="4"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></svg>',
                'deal' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h4l3 8l4 -16l3 8h4"/></svg>',
                'buyer' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7v-2a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v2"/></svg>',
                'property' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l18 0"/><path d="M5 21v-14l8 -4v18"/><path d="M19 21v-10l-6 -4"/><path d="M9 9l0 .01"/><path d="M9 12l0 .01"/><path d="M9 15l0 .01"/></svg>',
            ];
        @endphp
        <a href="{{ $result['url'] }}" class="list-group-item list-group-item-action">
            <div class="row align-items-center">
                <div class="col-auto">
                    <span class="avatar avatar-sm {{ $typeColors[$result['type']] ?? 'bg-secondary' }}-lt">
                        {!! $typeIcons[$result['type']] ?? '' !!}
                    </span>
                </div>
                <div class="col">
                    <strong>{{ $result['title'] }}</strong>
                    @if($result['subtitle'])
                        <div class="text-secondary small">{{ $result['subtitle'] }}</div>
                    @endif
                </div>
                <div class="col-auto">
                    <span class="badge {{ $typeColors[$result['type']] ?? 'bg-secondary' }}-lt">{{ __(ucfirst($result['type'])) }}</span>
                </div>
            </div>
        </a>
        @endforeach
    </div>
    @else
    <div class="card-body text-center text-muted py-4">
        {{ __('No results found for your search.') }}
    </div>
    @endif
</div>
@endsection
