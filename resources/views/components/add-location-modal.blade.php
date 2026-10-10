@props([
    'action',
    'cities' => [],
    'communities' => [],
    'returnPath' => null,
    'title' => __('Add location'),
])

@php
    $communityData = collect($communities)->map(function ($c) {
        return [
            'name' => (string) (is_array($c) ? ($c['name'] ?? '') : ($c->name ?? '')),
            'city' => (string) (is_array($c) ? ($c['city'] ?? '') : ($c->city ?? '')),
        ];
    })->filter(fn ($c) => $c['name'] !== '')->values();
@endphp

{{-- The single "Add Location" form: City → Community → Building. The city is
     picked from the list (or typed in), the community is then filtered to that
     city (or created), and the building name is de-duplicated on save. Shared by
     Settings → Locations and the inventory unit forms (New Unit / Edit). --}}
<div class="modal fade" id="addLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ $action }}" id="addLocationForm">
            @csrf
            @if($returnPath)
                <input type="hidden" name="return" value="{{ $returnPath }}">
            @endif
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $title }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">
                        {{ __('A building sits under a community, and a community under a city. Pick the city, then its community, then name the building — add a city or community on the fly if it is missing.') }}
                    </p>

                    <div class="mb-3">
                        <label class="form-label required">{{ __('City') }}</label>
                        <select name="city" data-add-city class="form-select" required>
                            <option value="">{{ __('Select a city...') }}</option>
                            @foreach($cities as $city)
                                <option value="{{ $city }}">{{ $city }}</option>
                            @endforeach
                            <option value="__new__">{{ __('＋ Add a new city…') }}</option>
                        </select>
                        <input type="text" name="new_city" data-add-new-city class="form-control mt-2 d-none" placeholder="{{ __('Type the new city name') }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label required">{{ __('Community') }}</label>
                        <select name="community" data-add-community class="form-select" required disabled>
                            <option value="">{{ __('Choose a community…') }}</option>
                            <option value="__new__">{{ __('＋ Add a new community…') }}</option>
                        </select>
                        <input type="text" name="new_community" data-add-new-community class="form-control mt-2 d-none" placeholder="{{ __('Type the new community name') }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label required">{{ __('Building / Sub-community') }}</label>
                        <input type="text" name="sub_community" data-add-building class="form-control" placeholder="{{ __('e.g. Sky Tower') }}" required>
                        <div class="form-hint">{{ __('If a building with a similar name already exists it is reused, never duplicated.') }}</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Save location') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="addLocationCommunityData">@json($communityData)</script>

@push('scripts')
<script>
(function () {
    var modalEl = document.getElementById('addLocationModal');
    if (!modalEl || modalEl.dataset.bound === '1') { return; }
    modalEl.dataset.bound = '1';

    var city = modalEl.querySelector('[data-add-city]');
    var newCity = modalEl.querySelector('[data-add-new-city]');
    var community = modalEl.querySelector('[data-add-community]');
    var newCommunity = modalEl.querySelector('[data-add-new-community]');
    var building = modalEl.querySelector('[data-add-building]');

    var dataEl = document.getElementById('addLocationCommunityData');
    var allCommunities = [];
    try { allCommunities = JSON.parse(dataEl ? dataEl.textContent : '[]'); } catch (e) { allCommunities = []; }

    function norm(v) { return String(v == null ? '' : v).trim().toLowerCase(); }

    function toggleNew(input, show) {
        if (!input) { return; }
        input.classList.toggle('d-none', !show);
        input.required = show;
        if (!show) { input.value = ''; }
    }

    // Safari/WebKit ignores <option hidden>, so the community list is rebuilt
    // rather than hidden: drop the injected options, then re-add the ones for
    // the chosen city. A brand-new city has none until its community is typed.
    function rebuildCommunities(cityValue) {
        Array.prototype.slice.call(community.querySelectorAll('option[data-dynamic]')).forEach(function (o) { o.remove(); });
        var anchor = community.querySelector('option[value="__new__"]');
        var selected = cityValue === null ? null : norm(cityValue);
        if (selected === '' || selected === '__new__') { return; }

        allCommunities.forEach(function (c) {
            if (selected !== null && norm(c.city) !== selected) { return; }
            var opt = document.createElement('option');
            opt.value = c.name;
            opt.setAttribute('data-dynamic', '1');
            opt.setAttribute('data-city', c.city || '');
            opt.textContent = c.name;
            community.insertBefore(opt, anchor);
        });
    }

    function onCityChange() {
        var value = city.value;
        toggleNew(newCity, value === '__new__');

        if (value === '') {
            community.disabled = true;
            community.value = '';
            rebuildCommunities('');
            toggleNew(newCommunity, false);
            return;
        }

        community.disabled = false;

        if (value === '__new__') {
            rebuildCommunities('__new__');
            community.value = '__new__';
            toggleNew(newCommunity, true);
        } else {
            rebuildCommunities(value);
            community.value = '';
            toggleNew(newCommunity, false);
        }
    }

    city.addEventListener('change', onCityChange);
    community.addEventListener('change', function () {
        toggleNew(newCommunity, community.value === '__new__');
    });

    function reset() {
        city.value = '';
        toggleNew(newCity, false);
        community.disabled = true;
        community.value = '';
        rebuildCommunities('');
        toggleNew(newCommunity, false);
        if (building) { building.value = ''; }
    }

    modalEl.addEventListener('show.bs.modal', function (event) {
        reset();

        // Opened by the inventory building picker's "Create" row: the typed name
        // is stashed globally so the picker does not navigate away.
        var pending = window.__ssCreateName;
        window.__ssCreateName = '';

        if (pending) {
            if (building) { building.value = pending; }
        } else {
            var trigger = event.relatedTarget;
            if (trigger) {
                var c = trigger.getAttribute('data-city') || '';
                var com = trigger.getAttribute('data-community') || '';
                if (c) {
                    city.value = c;
                    onCityChange();
                    if (com) { community.value = com; toggleNew(newCommunity, false); }
                } else if (com) {
                    // Community known but no city: show every community.
                    community.disabled = false;
                    rebuildCommunities(null);
                    community.value = com;
                }
            }
        }

        if (building) { setTimeout(function () { building.focus(); }, 150); }
    });

    // Opened by the inventory building picker's "Create" row.
    document.addEventListener('ss-create', function (e) {
        var name = e.detail && e.detail.name;
        if (!name) { return; }
        window.__ssCreateName = name;
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });
})();
</script>
@endpush
