@extends('layouts.app')

@section('title', __('Document Templates'))
@section('page-title', __('Document Templates'))

@section('breadcrumbs')
<li class="breadcrumb-item active" aria-current="page">{{ __('Document Templates') }}</li>
@endsection

@section('content')
@include('documents.templates._list', ['templates' => $templates])
@endsection