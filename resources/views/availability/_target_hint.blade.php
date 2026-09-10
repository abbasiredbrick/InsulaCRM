{{ __('Your sheet columns map onto these fields:') }}
<strong>{{ __('unit_no') }}</strong> {{ __('(required to identify a unit),') }}
<strong>{{ __('building') }}</strong> {{ __('e.g. "Canal Residence" — tick "repeat" and it fills down for every unit in that block,') }}
<strong>{{ __('rent / deposit / admin_fee') }}</strong>, <strong>{{ __('status') }}</strong> {{ __('(Vacant, Up-coming, Under Offer…)'), }}
<strong>{{ __('key_date') }}</strong> {{ __('(handle-over/vacancy date),') }}
<strong>{{ __('features') }}</strong> {{ __('(beds, size "352 Sq Mtr", kitchen, view — parsed automatically),') }}
<strong>{{ __('amenities, remarks, parking, community') }}</strong>.
{{ __('A row is skipped if it has no unit number. Anything not in your mapping is ignored.') }}