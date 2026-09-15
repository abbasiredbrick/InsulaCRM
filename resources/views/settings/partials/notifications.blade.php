@php
    $prefs = $tenant->notification_preferences ?? [];
    $types = [
        'lead_assigned' => [
            'label' => __('Lead Assigned'),
            'description' => __('Notify agents when a new lead is assigned to them.'),
        ],
        'lead_reassigned' => [
            'label' => __('Lead Reassigned'),
            'description' => __('Notify the old and new agent when a lead is moved to another agent.'),
        ],
        'team_activity' => [
            'label' => __('Team Activity'),
            'description' => __('Notify managers whenever a team member logs activity on a lead assigned to their team.'),
        ],
        'team_reassigned' => [
            'label' => __('Team Reassignment'),
            'description' => __('Notify managers when a lead in their team changes hands.'),
        ],
        'deal_stage_changed' => [
            'label' => __('Deal Stage Changed'),
            'description' => __('Notify agents when a deal moves to a new pipeline stage.'),
        ],
        'due_diligence_warning' => [
            'label' => __($businessMode === 'realestate' ? 'Transaction Deadline Warning' : 'Due Diligence Warning'),
            'description' => __($businessMode === 'realestate' ? 'Notify agents and admins when a transaction deadline is within 3 days.' : 'Notify agents and admins when a due diligence deadline is within 3 days.'),
        ],
        'buyer_matched' => [
            'label' => __($businessMode === 'realestate' ? 'Client Match Found' : 'Buyer Match Found'),
            'description' => __($businessMode === 'realestate' ? 'Notify admins and agents when client matches are found for a listing.' : 'Notify admins and disposition agents when buyer matches are found for a deal.'),
        ],
        'team_member_invited' => [
            'label' => __('Team Member Invited'),
            'description' => __('Send a welcome email when a new team member is added.'),
        ],
        'sequence_email' => [
            'label' => __('Sequence Emails'),
            'description' => __('Send drip sequence emails to leads when a step has action type "email".'),
        ],
        'lease_expiry_reminder' => [
            'label' => __('Lease Expiry Reminders'),
            'description' => __('Notify agents and their managers 45 days before a lease contract expires, so the client can renew or move.'),
        ],
        'calendar_reminders' => [
            'label' => __('Calendar / Schedule Reminders'),
            'description' => __("Email and in-app reminders before viewings, meetings, follow-ups and open houses when they are not synced to a Google/Microsoft calendar."),
        ],
        'availability_conflict' => [
            'label' => __('Listed Unit Decisions'),
            'description' => __($businessMode === 'realestate' ? "Notify the agent and admins when a PM availability sheet shows a currently-listed unit as leased, so they can decide to keep it listed or unlist it." : 'Notify agents when a PM availability sheet shows a currently-listed unit as leased.'),
        ],
    ];
@endphp

<form action="{{ route('settings.updateNotifications') }}" method="POST">
    @csrf
    @method('PUT')

    <h3 class="mb-3">{{ __('Email Notification Preferences') }}</h3>
    <p class="text-secondary mb-4">{{ __('Control which email notifications are sent from your account. All notifications are enabled by default.') }}</p>

    <div class="divide-y">
        @foreach($types as $key => $info)
            <div class="row align-items-center py-3">
                <div class="col-auto">
                    <label class="form-check form-switch form-switch-lg">
                        <input type="hidden" name="{{ $key }}" value="0">
                        <input class="form-check-input" type="checkbox" name="{{ $key }}" value="1"
                            {{ ($prefs[$key] ?? true) ? 'checked' : '' }}>
                    </label>
                </div>
                <div class="col">
                    <strong>{{ $info['label'] }}</strong>
                    <div class="text-secondary">{{ $info['description'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary">{{ __('Save Notification Preferences') }}</button>
    </div>
</form>
