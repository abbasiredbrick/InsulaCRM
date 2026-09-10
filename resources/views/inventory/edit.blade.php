@extends('layouts.app')

@section('title', __('Edit Unit'))
@section('page-title', __('Edit Unit'))

@section('content')
@include('inventory._form', ['property' => $property, 'editMode' => true])
@endsection