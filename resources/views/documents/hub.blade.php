@extends('layouts.app')

@section('title', __('Documents & Agreements'))
@section('page-title', __('Documents & Agreements'))

@section('breadcrumbs')
<li class="breadcrumb-item active" aria-current="page">{{ __('Documents & Agreements') }}</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <ul class="nav nav-pills nav-pill-secondary">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'agreements' ? 'active' : '' }}" href="{{ route('documents.hub', ['tab' => 'agreements']) }}">{{ __('A2A Contracts') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'offers' ? 'active' : '' }}" href="{{ route('documents.hub', ['tab' => 'offers']) }}">{{ __('Offer Letters') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'generated' ? 'active' : '' }}" href="{{ route('documents.hub', ['tab' => 'generated']) }}">{{ __('Generated') }}</a>
        </li>
        @if(auth()->user()->isAdmin())
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'templates' ? 'active' : '' }}" href="{{ route('documents.hub', ['tab' => 'templates']) }}">{{ __('Templates') }}</a>
        </li>
        @endif
    </ul>
</div>

@if($tab === 'agreements')
    @include('a2a._list', ['contracts' => $contracts])
@elseif($tab === 'offers')
    @include('documents._offers_list', ['offerLetters' => $offerLetters])
@elseif($tab === 'generated')
    @include('documents._generated_list', ['documents' => $documents])
@elseif($tab === 'templates')
    @include('documents.templates._list', ['templates' => $templates])
@endif
@endsection