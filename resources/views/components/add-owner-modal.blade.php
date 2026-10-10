@props([
    'action' => '',
    'returnPath' => '',
    'title' => __('Add Owner'),
])

{{--
    Shared "Add Owner" form: the unit form's owner picker and Settings → Owners
    both open this. Owner name/phone/email/office address plus an office
    location (paste a Google Maps link or type the address) so the office can be
    opened on a map and driven to via Google Maps or Waze. De-dup is
    normalized-exact server-side, so a corrected spelling reuses the owner.
--}}
<div class="modal fade" id="addOwnerModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ $action }}" data-owner-form>
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
                        {{ __('An owner is stored once and linked to their units, so the same person is not retyped on every unit. If an owner with the same name already exists it is reused, never duplicated.') }}
                    </p>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">{{ __('Owner name') }}</label>
                            <input type="text" name="name" data-add-owner-name class="form-control" placeholder="{{ __('e.g. Ahmed Al Mansoori') }}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Phone') }}</label>
                            <input type="text" name="phone" class="form-control" placeholder="+971 50 000 0000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Email') }}</label>
                            <input type="email" name="email" class="form-control" placeholder="name@example.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Office address') }}</label>
                            <input type="text" name="office_address" class="form-control" placeholder="{{ __('e.g. Office 1204, Business Bay') }}">
                        </div>
                        <div class="col-12 mb-1">
                            <label class="form-label">{{ __('Office location') }}</label>
                            <input type="text" name="office_location" class="form-control" placeholder="{{ __('Paste a Google Maps link or type the office address') }}">
                            <div class="form-hint">{{ __('Used to open the office on a map and to drive there with Google Maps or Waze.') }}</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Save owner') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var modalEl = document.getElementById('addOwnerModal');
    if (!modalEl || modalEl.dataset.bound === '1') { return; }
    modalEl.dataset.bound = '1';

    var nameInput = modalEl.querySelector('[data-add-owner-name]');

    // Opened by the unit form's owner picker "Create" row: only react to the
    // picker wired to THIS modal (a page may host more than one create-modal).
    document.addEventListener('ss-create', function (e) {
        var name = e.detail && e.detail.name;
        if (!name) { return; }
        if (e.detail && e.detail.modal && e.detail.modal !== '#' + modalEl.id) { return; }
        window.__ssCreateName = name;
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });

    modalEl.addEventListener('show.bs.modal', function () {
        var typed = window.__ssCreateName;
        window.__ssCreateName = null;
        if (typed && nameInput) {
            nameInput.value = typed;
        }
        if (nameInput) { setTimeout(function () { nameInput.focus(); }, 150); }
    });
})();
</script>
@endpush
