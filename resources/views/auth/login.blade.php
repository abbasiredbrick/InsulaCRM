@extends('layouts.auth')

@section('title', __('Login'))

@section('content')
<div class="card card-md">
    <div class="card-body">
        <h2 class="h2 text-center mb-4">{{ __('Login to your account') }}</h2>
        <form action="{{ route('login') }}" method="POST" autocomplete="off">
            @csrf
            <div class="mb-3">
                <label class="form-label">{{ __('Email address') }}</label>
                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                       placeholder="{{ __('your@email.com') }}" value="{{ old('email') }}" required autofocus>
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-2">
                <label class="form-label">
                    {{ __('Password') }}
                    <span class="form-label-description">
                        <a href="{{ route('password.request') }}">{{ __('I forgot password') }}</a>
                    </span>
                </label>
                <div class="input-group input-group-flat">
                    <input type="password" name="password" id="login-password"
                           class="form-control @error('password') is-invalid @enderror"
                           placeholder="{{ __('Your password') }}" required>
                    <span class="input-group-text">
                        <a href="#" class="link-secondary" id="toggle-password" role="button"
                           aria-pressed="false" aria-label="{{ __('Show password') }}" title="{{ __('Show password') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" id="icon-eye" class="icon icon-tabler icon-tabler-eye" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/></svg>
                            <svg xmlns="http://www.w3.org/2000/svg" id="icon-eye-off" class="icon icon-tabler icon-tabler-eye-off d-none" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="3" y1="3" x2="21" y2="21"/><path d="M10.584 10.587a2 2 0 0 0 2.828 2.829"/><path d="M9.363 5.365a9.466 9.466 0 0 1 2.637 -.365c3.6 0 6.6 2 9 6c-1.587 2.646 -3.61 4.61 -5.87 5.5m-2.13 .5c-3.6 0 -6.6 -2 -9 -6c.65 -1.083 1.327 -2.03 2.03 -2.84"/></svg>
                        </a>
                    </span>
                </div>
                @error('password')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-2">
                <label class="form-check">
                    <input type="checkbox" name="remember" class="form-check-input"/>
                    <span class="form-check-label">{{ __('Remember me on this device') }}</span>
                </label>
            </div>
            <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100">{{ __('Sign in') }}</button>
            </div>
        </form>
        @if(!empty($ssoProviders ?? []))
        <div class="hr-text">{{ __('or') }}</div>
        <div class="card-body pt-0">
            @foreach($ssoProviders as $provider)
            <a href="{{ route('sso.redirect', $provider['driver']) }}" class="btn btn-outline-secondary w-100 {{ !$loop->last ? 'mb-2' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></svg>
                {{ __('Sign in with :provider', ['provider' => $provider['name']]) }}
            </a>
            @endforeach
        </div>
        @endif
    </div>
</div>
<div class="text-center text-secondary mt-3">
    {{ __('Need an account?') }} <a href="https://redbrickworks.com" target="_blank" rel="noopener">{{ __('Contact us') }}</a>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var toggle = document.getElementById('toggle-password');
        var input = document.getElementById('login-password');
        if (!toggle || !input) return;

        var eye = document.getElementById('icon-eye');
        var eyeOff = document.getElementById('icon-eye-off');

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            if (eye) eye.classList.toggle('d-none', show);
            if (eyeOff) eyeOff.classList.toggle('d-none', !show);
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}');
            toggle.setAttribute('title', show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}');
            input.focus();
        });
    })();
</script>
@endpush
