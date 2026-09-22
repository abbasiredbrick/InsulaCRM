@extends('layouts.app')

@section('title', __('A2A Contracts'))
@section('page-title', __('A2A Contracts'))

@section('breadcrumbs')
<li class="breadcrumb-item active" aria-current="page">{{ __('A2A Contracts') }}</li>
@endsection

@section('content')
@include('a2a._list', ['contracts' => $contracts])
@endsection