<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $tenant->name }} – {{ __('Available Units') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bp-primary: #0054a6;
            --bp-primary-dark: #003d7a;
            --bp-primary-light: #e8f0fe;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f8f9fa;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .bp-hero {
            background: linear-gradient(135deg, var(--bp-primary) 0%, var(--bp-primary-dark) 100%);
            color: #fff;
            padding: 3rem 0;
        }
        .bp-hero .bp-logo {
            max-height: 64px;
            max-width: 200px;
            margin-bottom: 1rem;
        }
        .bp-hero h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .bp-hero p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 620px;
            margin-bottom: 0;
        }
        .gate-card {
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            padding: 2rem;
        }
        .btn-primary {
            background-color: var(--bp-primary);
            border-color: var(--bp-primary);
        }
        .btn-primary:hover {
            background-color: var(--bp-primary-dark);
            border-color: var(--bp-primary-dark);
        }
        .bp-footer {
            background: #1e293b;
            color: #94a3b8;
            padding: 1.5rem 0;
            margin-top: auto;
        }
    </style>
</head>
<body>
    <div class="bp-hero">
        <div class="container">
            @if($tenant->logo_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_path) }}" alt="{{ $tenant->name }}" class="bp-logo">
            @endif
            <h1>{{ $tenant->name }}</h1>
            <p>{{ __('Browse our available rental units and save the ones you like.') }}</p>
        </div>
    </div>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-6">
                <div class="gate-card">
                    <h2 class="mb-1" style="font-size: 1.25rem; font-weight: 700;">{{ __('Confirm who you are') }}</h2>
                    <p class="text-muted small mb-4">{{ __('Enter your details to view the available units. If we already have your details, you will be matched to your existing profile.') }}</p>

                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form action="{{ route('share.verify', array_merge(['slug' => $tenant->slug], $filters)) }}" method="POST">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('First Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name') }}" required>
                                @error('first_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Last Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name') }}" required>
                                @error('last_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Phone') }} <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}">
                                @error('phone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Email') }}</label>
                                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}">
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    {{ __('View Available Units') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer class="bp-footer">
        <div class="container text-center">
            <p class="mb-0">&copy; {{ date('Y') }} {{ $tenant->name }}. {{ __('All rights reserved.') }}</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>