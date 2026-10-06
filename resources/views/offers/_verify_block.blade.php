{{--
    The verification block: proof the letter is genuine, for a reader who has no
    account and no login.

    The URL carries a signed, unguessable token, so it cannot be edited to point
    at another offer and letters cannot be enumerated from it.

    Printed at the foot of the LAST sheet only — it used to print on the first,
    which made the reader flip back for it on a two-page letter, and let the code
    straddle a page boundary and print unscannable. `offers/letter.blade.php`
    decides which sheet that is and includes this once.

    $anchor  push the block to the foot of its sheet (flex column on the page).
             Off when it should just follow the content it sits under.
--}}
@if($qrSvg)
<div class="verify{{ $anchor ? ' verify-anchor' : '' }}">
    <div class="verify-qr">{!! $qrSvg !!}</div>
    <div class="verify-text">
        <strong>{{ __('Verify this offer letter') }}</strong>
        <div>{{ __('Scan the code to confirm this letter was genuinely issued by') }} {{ $tenant->name }}.</div>
        <div class="verify-url">{{ $verifyUrl }}</div>
    </div>
</div>
@endif