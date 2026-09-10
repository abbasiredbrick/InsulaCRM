@extends('layouts.app')

@section('title', __('Add Unit'))
@section('page-title', __('Add Unit'))

@section('content')
@include('inventory._form', ['property' => $property, 'editMode' => false])
@endsection