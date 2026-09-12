@extends('layouts.public')

@section('title', config('app.name', 'Keystone') . ' — CRM for Property Brokers')

@section('content')
<div class="landing-hero">
    <div class="container">
        <div class="row align-items-center gy-5">
            <div class="col-lg-7">
                <div class="landing-badge">Keystone CRM</div>
                <h1 class="landing-title">The CRM built for <span class="text-gradient">property brokers</span></h1>
                <p class="landing-subtitle">
                    Manage your leads, deals, buyers and listings in one place. Capture leads from portals,
                    move deals through the pipeline, and close faster — on desktop or your phone.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <a href="{{ route('login') }}" class="btn btn-primary btn-lg">{{ __('Sign in') }}</a>
                    <a href="#features" class="btn btn-outline-secondary btn-lg">{{ __('Explore features') }}</a>
                </div>
            </div>
            <div class="col-lg-5">
                <img src="{{ asset('images/logo.png') . '?v=keystone' }}" alt="{{ config('app.name') }}" class="landing-logo">
            </div>
        </div>
    </div>
</div>

<div class="landing-section" id="features">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="landing-h2">Everything a broker needs</h2>
            <p class="text-secondary">One platform to run your entire book of business.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="landing-card-title">Deal pipeline</h3>
                        <p class="text-secondary mb-0">Track every mandate from first contact to closing with a clear pipeline and stage management.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="landing-card-title">Portal lead capture</h3>
                        <p class="text-secondary mb-0">Receive leads automatically from Bayut, Dubizzle and Property Finder webhooks, plus public web forms.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="landing-card-title">Buyers & matching</h3>
                        <p class="text-secondary mb-0">Maintain a buyer database, score their intent, and match them to new inventory instantly.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="landing-card-title">Inventory & listings</h3>
                        <p class="text-secondary mb-0">Manage your units, availability, photos and portal status, and push listings to portals.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="landing-card-title">AI assistant</h3>
                        <p class="text-secondary mb-0">Draft follow-ups, analyze deals, score leads, and get pipeline insights with built-in AI tools.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="landing-card-title">Reports & analytics</h3>
                        <p class="text-secondary mb-0">Team performance, funnel, lead-source costs and pipeline reports to keep you on target.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="landing-section landing-cta">
    <div class="container text-center">
        <h2 class="landing-h2 text-white">Interested in Keystone?</h2>
        <p class="text-secondary mb-4">Partner with Red Brick Smart Systems and bring Keystone to your brokerage.</p>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="https://redbrickworks.com" target="_blank" rel="noopener" class="btn btn-primary btn-lg">Visit redbrickworks.com</a>
            <a href="mailto:sales@redbrickworks.com" class="btn btn-outline-light btn-lg">Email sales@redbrickworks.com</a>
        </div>
    </div>
</div>
@endsection