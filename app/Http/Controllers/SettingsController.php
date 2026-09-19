<?php

namespace App\Http\Controllers;

use App\Http\Requests\DistributionSettingsRequest;
use App\Http\Requests\GeneralSettingsRequest;
use App\Models\AuditLog;
use App\Models\CustomFieldDefinition;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSourceCost;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TeamMemberInvited;
use App\Services\BusinessModeService;
use App\Services\CustomFieldService;
use App\Services\Settings\BackupService;
use App\Services\Settings\LanguageFileService;
use App\Services\UpdateManagerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly LanguageFileService $languageFileService,
    ) {}

    public function index()
    {
        $tenant = auth()->user()->tenant;
        $teamMembers = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('id', '!=', auth()->id())
            ->with(['role', 'secondaryRoles'])
            ->get();

        $roles = Role::where(function ($q) use ($tenant) {
            $q->where('is_system', true)->orWhere('tenant_id', $tenant->id);
        })->get();
        $modeRoles = \App\Services\BusinessModeService::isRealEstate($tenant)
            ? \App\Services\BusinessModeService::REALESTATE_ROLES
            : \App\Services\BusinessModeService::WHOLESALE_ROLES;
        $roles = $roles->filter(fn ($role) => in_array($role->name, $modeRoles));

        // Roles the current actor is allowed to assign: strictly below their
        // own rank. The Owner role is never assignable here (ownership moves
        // only through the explicit transfer flow).
        $assignableRoles = $roles
            ->filter(fn ($role) => $role->name !== 'owner' && auth()->user()->roleRank() > $role->rank())
            ->values();

        // Operational roles that may be added on top of a member's primary
        // role (e.g. Cold Call Agent). Owner/Admin stay primary-only.
        $secondaryRoleOptions = $roles
            ->filter(fn ($role) => ! in_array($role->name, ['owner', 'admin'], true))
            ->values();

        $leadSourceCosts = LeadSourceCost::where('tenant_id', $tenant->id)->pluck('monthly_budget', 'lead_source');
        $webhooks = \App\Models\Webhook::where('tenant_id', $tenant->id)->latest()->get();
        $updateManager = app(UpdateManagerService::class);
        $updateManagerReady = $updateManager->schemaReady();
        $preparedUpdate = $updateManager->preparedUpdateForTenant($tenant->id);
        $updateHistory = $updateManager->historyForTenant($tenant->id);
        $manualSnapshots = $updateManager->manualSnapshotsForTenant($tenant->id);

        // For backward compat, pass as both 'agents' and 'teamMembers'
        $agents = $teamMembers;

        // Every team member, including the current admin: deleting a member
        // requires handing their records to somebody who remains.
        $reassignTargets = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        $businessModeImpact = $this->businessModeImpact($tenant);

        // Commission formula + per-member compensation plans.
        $commissionSettings = $tenant->commissionCalculationSettings();
        $compensationPlans = \App\Models\AgentCompensation::get()->keyBy('user_id');

        return view('settings.index', compact('tenant', 'agents', 'teamMembers', 'roles', 'assignableRoles', 'secondaryRoleOptions', 'leadSourceCosts', 'webhooks', 'preparedUpdate', 'updateHistory', 'manualSnapshots', 'updateManagerReady', 'reassignTargets', 'businessModeImpact', 'commissionSettings', 'compensationPlans'));
    }

    public function updateGeneral(GeneralSettingsRequest $request)
    {
        $tenant = auth()->user()->tenant;

        $data = $request->only(['name', 'timezone', 'currency', 'date_format', 'country', 'measurement_system', 'locale']);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('logos', 'public');
            $data['logo_path'] = $path;
        }

        $tenant->update($data);

        AuditLog::log('settings.updated', $tenant);

        return redirect()->route('settings.index', ['tab' => 'general'])->with('success', 'Settings updated.');
    }

    public function updateStorage(Request $request)
    {
        $request->validate([
            'storage_disk' => 'required|in:local,s3',
            's3_key' => 'nullable|string|max:255',
            's3_secret' => 'nullable|string|max:255',
            's3_region' => 'nullable|string|max:100',
            's3_bucket' => 'nullable|string|max:255',
            's3_url' => 'nullable|url|max:500',
        ]);

        $tenant = auth()->user()->tenant;
        $options = $tenant->custom_options ?? [];

        if ($request->storage_disk === 's3') {
            $options['s3_key'] = $request->s3_key;
            // Only update secret if a new one was provided
            if ($request->filled('s3_secret')) {
                $options['s3_secret'] = encrypt($request->s3_secret);
            }
            $options['s3_region'] = $request->s3_region ?? 'us-east-1';
            $options['s3_bucket'] = $request->s3_bucket;
            $options['s3_url'] = $request->s3_url;
        }

        $tenant->update([
            'storage_disk' => $request->storage_disk,
            'custom_options' => $options,
        ]);

        AuditLog::log('settings.storage_updated', $tenant, ['disk' => $request->storage_disk]);

        return redirect()->route('settings.index', ['tab' => 'storage'])->with('success', __('Storage settings updated.'));
    }

    /**
     * Save the tenant-level OAuth app credentials used for Google/Microsoft
     * calendar + drive connections.
     */
    public function updateCloud(Request $request)
    {
        $request->validate([
            'google_client_id' => 'nullable|string|max:255',
            'google_client_secret' => 'nullable|string|max:255',
            'microsoft_client_id' => 'nullable|string|max:255',
            'microsoft_client_secret' => 'nullable|string|max:255',
        ]);

        $tenant = auth()->user()->tenant;
        $data = [];

        $data['google_client_id'] = $request->google_client_id;
        if ($request->filled('google_client_secret')) {
            $data['google_client_secret'] = $request->google_client_secret;
        }

        $data['microsoft_client_id'] = $request->microsoft_client_id;
        if ($request->filled('microsoft_client_secret')) {
            $data['microsoft_client_secret'] = $request->microsoft_client_secret;
        }

        $tenant->update($data);

        AuditLog::log('settings.cloud_updated', $tenant);

        return redirect()->route('settings.index', ['tab' => 'integrations'])->with('success', __('Cloud connection settings updated.'));
    }

    /**
     * Save the tenant-level Google/Microsoft calendar integration toggle and
     * the default reminder lead time used when a schedule has no override.
     */
    public function updateCalendarIntegration(Request $request)
    {
        $request->validate([
            'calendar_sync_enabled' => 'sometimes|boolean',
            'calendar_reminder_default_minutes' => 'nullable|integer|min:1|max:10080',
        ]);

        $tenant = auth()->user()->tenant;
        $tenant->update([
            'calendar_sync_enabled' => $request->boolean('calendar_sync_enabled'),
            'calendar_reminder_default_minutes' => $request->filled('calendar_reminder_default_minutes')
                ? (int) $request->input('calendar_reminder_default_minutes')
                : null,
        ]);

        AuditLog::log('settings.calendar_integration_updated', $tenant);

        return redirect()->route('settings.index', ['tab' => 'integrations'])
            ->with('success', __('Calendar integration settings updated.'));
    }

    /**
     * Test S3 connection with the provided or saved credentials.
     */
    public function testS3Connection(Request $request)
    {
        $request->validate([
            's3_key' => 'required|string',
            's3_region' => 'required|string',
            's3_bucket' => 'required|string',
            's3_secret' => 'nullable|string',
            's3_url' => 'nullable|string',
        ]);

        // Check if the S3 driver package is installed
        if (! class_exists(\Aws\S3\S3Client::class)) {
            return response()->json([
                'success' => false,
                'message' => __('The S3 storage driver is missing from this deployment. Run composer install to install the packaged dependencies, then try again.'),
            ], 422);
        }

        $tenant = auth()->user()->tenant;
        $options = $tenant->custom_options ?? [];

        // Use provided secret, or fall back to saved encrypted secret
        $secret = $request->s3_secret;
        if (empty($secret) && ! empty($options['s3_secret'])) {
            try {
                $secret = decrypt($options['s3_secret']);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => __('Saved secret could not be decrypted. Please re-enter your Secret Access Key.'),
                ], 422);
            }
        }

        if (empty($secret)) {
            return response()->json([
                'success' => false,
                'message' => __('Secret Access Key is required to test the connection.'),
            ], 422);
        }

        try {
            $config = [
                'driver' => 's3',
                'key' => $request->s3_key,
                'secret' => $secret,
                'region' => $request->s3_region,
                'bucket' => $request->s3_bucket,
                'throw' => true,
            ];

            if ($request->filled('s3_url')) {
                $config['endpoint'] = $request->s3_url;
                $config['use_path_style_endpoint'] = true;
            }

            // Build a temporary S3 disk and attempt to write/read/delete a test file
            $disk = \Illuminate\Support\Facades\Storage::build($config);

            $testFile = '.keystone-connection-test-'.uniqid();
            $disk->put($testFile, 'ok');
            $content = $disk->get($testFile);
            $disk->delete($testFile);

            if ($content !== 'ok') {
                return response()->json([
                    'success' => false,
                    'message' => __('Connected but read/write verification failed.'),
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => __('Connection successful! Bucket is accessible and writable.'),
            ]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();

            // Provide friendlier messages for common errors
            if (str_contains($msg, 'InvalidAccessKeyId') || str_contains($msg, 'SignatureDoesNotMatch')) {
                $msg = __('Invalid credentials. Check your Access Key and Secret.');
            } elseif (str_contains($msg, 'NoSuchBucket')) {
                $msg = __('Bucket does not exist. Check the bucket name and region.');
            } elseif (str_contains($msg, 'AccessDenied')) {
                $msg = __('Access denied. The credentials lack permission for this bucket.');
            } elseif (str_contains($msg, 'Could not resolve host') || str_contains($msg, 'cURL error')) {
                $msg = __('Could not reach the S3 endpoint. Check the URL and your network.');
            }

            return response()->json([
                'success' => false,
                'message' => $msg,
            ], 422);
        }
    }

    /**
     * Send a test SMS to verify Twilio configuration.
     */
    public function testSms(Request $request)
    {
        $request->validate([
            'to' => 'required|string',
        ]);

        $tenant = auth()->user()->tenant;
        $manager = app(\App\Integrations\IntegrationManager::class);
        $provider = $manager->getSmsProvider($tenant->id);

        if ($provider->driver() === 'log') {
            return response()->json([
                'success' => false,
                'message' => __('No SMS provider is active. Configure and enable a provider first.'),
            ], 422);
        }

        try {
            $result = $provider->send(
                $request->to,
                __('This is a test message from :app.', ['app' => $tenant->name ?? config('app.name')])
            );

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => __('Test SMS sent successfully to :number.', ['number' => $request->to]),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => __('SMS sending failed. Check your provider credentials and the application log for details.'),
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SMS test failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => __('SMS sending failed. Check your provider credentials and the application log for details.'),
            ], 422);
        }
    }

    public function backupList()
    {
        return response()->json(['backups' => $this->backupService->list()]);
    }

    public function backupCreate()
    {
        if ($this->backupService->create()) {
            AuditLog::log('backup.created', auth()->user()->tenant);

            return response()->json(['success' => true, 'message' => __('Backup created successfully.'), 'backups' => $this->backupService->list()]);
        }

        return response()->json(['success' => false, 'message' => __('Backup failed. Check server logs for details.')], 500);
    }

    public function backupDownload(string $filename)
    {
        $filepath = $this->backupService->path($filename);
        if (! $filepath) {
            abort(404);
        }

        return response()->download($filepath);
    }

    public function backupDelete(string $filename)
    {
        if (! $this->backupService->delete($filename)) {
            return response()->json(['success' => false, 'message' => __('File not found.')], 404);
        }

        AuditLog::log('backup.deleted', auth()->user()->tenant, ['file' => basename($filename)]);

        return response()->json(['success' => true, 'message' => __('Backup deleted.'), 'backups' => $this->backupService->list()]);
    }

    public function apiLogs()
    {
        $logs = DB::table('api_logs')
            ->where('tenant_id', auth()->user()->tenant_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function ($log) {
                return [
                    'method' => $log->method,
                    'path' => $log->path,
                    'status' => $log->status_code,
                    'ip' => $log->ip_address,
                    'duration' => $log->duration_ms.'ms',
                    'date' => \Carbon\Carbon::parse($log->created_at)->diffForHumans(),
                ];
            });

        return response()->json(['logs' => $logs]);
    }

    /**
     * Roles the acting user may assign: strictly below their own rank,
     * relevant to the tenant's business mode (or a custom tenant role). The
     * Owner role is never assignable here — ownership only moves through
     * transferOwnership().
     */
    private function assignableRoleIdsFor(User $actor, Tenant $tenant): array
    {
        $modeRoleNames = \App\Services\BusinessModeService::getRoles($tenant);

        return Role::query()
            ->where(function ($q) use ($tenant) {
                $q->where('is_system', true)->orWhere('tenant_id', $tenant->id);
            })
            ->get()
            ->filter(function (Role $role) use ($actor, $modeRoleNames, $tenant) {
                $relevant = $role->is_system
                    ? in_array($role->name, $modeRoleNames, true)
                    : $role->tenant_id === $tenant->id;

                return $relevant
                    && $role->name !== 'owner'
                    && $actor->roleRank() > $role->rank();
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Roles that may be granted as additional (secondary) roles on top of a
     * member's primary role. Owner and Admin are privilege roles that can only
     * ever be a member's primary role.
     */
    private function secondaryRoleIdsFor(Tenant $tenant): array
    {
        $modeRoleNames = \App\Services\BusinessModeService::getRoles($tenant);

        return Role::query()
            ->where(function ($q) use ($tenant) {
                $q->where('is_system', true)->orWhere('tenant_id', $tenant->id);
            })
            ->get()
            ->filter(function (Role $role) use ($modeRoleNames, $tenant) {
                $relevant = $role->is_system
                    ? in_array($role->name, $modeRoleNames, true)
                    : $role->tenant_id === $tenant->id;

                return $relevant && ! in_array($role->name, ['owner', 'admin'], true);
            })
            ->pluck('id')
            ->all();
    }

    public function inviteAgent(Request $request)
    {
        $tenant = auth()->user()->tenant;
        $allowedRoleIds = $this->assignableRoleIdsFor(auth()->user(), $tenant);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role_id' => ['required', 'exists:roles,id', \Illuminate\Validation\Rule::in($allowedRoleIds)],
            'additional_roles' => 'nullable|array',
            'additional_roles.*' => ['integer', \Illuminate\Validation\Rule::in($this->secondaryRoleIdsFor($tenant))],
            'reports_to' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if ($value && ! User::where('tenant_id', auth()->user()->tenant_id)->where('id', $value)->exists()) {
                    $fail(__('The manager must be a member of this tenant.'));
                }
            }],
        ]);

        $agent = User::create([
            'tenant_id' => auth()->user()->tenant_id,
            'role_id' => $request->role_id,
            'reports_to' => $request->filled('reports_to') ? $request->reports_to : null,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $secondaryRoleIds = collect($request->input('additional_roles', []))
            ->diff([$request->role_id])
            ->all();
        $agent->secondaryRoles()->sync($secondaryRoleIds);

        AuditLog::log('agent.invited', $agent);

        // Notify the new team member
        $tenant = auth()->user()->tenant;
        if ($tenant->wantsNotification('team_member_invited')) {
            $roleName = Role::find($request->role_id)->name ?? 'agent';
            $agent->notify(new TeamMemberInvited($tenant, $roleName));
        }

        return redirect()->route('settings.index', ['tab' => 'team'])->with('success', 'Team member added successfully.');
    }

    public function updateAgent(Request $request, User $user)
    {
        $this->authorize('manageTeamMember', $user);

        $tenant = auth()->user()->tenant;
        $allowedRoleIds = $this->assignableRoleIdsFor(auth()->user(), $tenant);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role_id' => ['required', 'exists:roles,id', Rule::in($allowedRoleIds)],
            'additional_roles' => 'nullable|array',
            'additional_roles.*' => ['integer', Rule::in($this->secondaryRoleIdsFor($tenant))],
            'reports_to' => ['nullable', 'integer', Rule::notIn([$user->id]), function ($attribute, $value, $fail) {
                if ($value && ! User::where('tenant_id', auth()->user()->tenant_id)->where('id', $value)->exists()) {
                    $fail(__('The manager must be a member of this tenant.'));
                }
            }],
        ]);

        $newRole = Role::find($validated['role_id']);
        if ($user->roleRank() >= 2 && ($newRole?->rank() ?? 1) < 2) {
            $remainingElevated = User::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('id', '!=', $user->id)
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin']))
                ->count();

            if ($remainingElevated === 0) {
                return redirect()->route('settings.index', ['tab' => 'team'])
                    ->with('error', __('You cannot remove the last administrator of this workspace.'));
            }
        }

        $oldEmail = $user->email;

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role_id = $validated['role_id'];
        $user->reports_to = $request->filled('reports_to') ? $validated['reports_to'] : null;

        if ($oldEmail !== $validated['email']) {
            $user->email_verified_at = null;
        }

        $user->save();

        $secondaryRoleIds = collect($request->input('additional_roles', []))
            ->diff([$user->role_id])
            ->all();
        $user->secondaryRoles()->sync($secondaryRoleIds);

        AuditLog::log('agent.updated', $user, ['email' => $oldEmail], [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'reports_to' => $user->reports_to,
        ]);

        return redirect()->route('settings.index', ['tab' => 'team'])
            ->with('success', __('Team member updated.'));
    }

    public function toggleAgent(User $user)
    {
        $this->authorize('manageTeamMember', $user);

        $user->update(['is_active' => ! $user->is_active]);

        AuditLog::log('agent.toggled', $user);

        return redirect()->route('settings.index', ['tab' => 'team'])->with('success', 'Agent status updated.');
    }

    /**
     * Reset a team member's password (admin action, no mail required).
     */
    public function resetPasswordAgent(Request $request, User $user)
    {
        $this->authorize('manageTeamMember', $user);

        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        AuditLog::log('agent.password_reset', $user);

        return redirect()->route('settings.index', ['tab' => 'team'])
            ->with('success', __('Password has been reset for :name.', ['name' => $user->name]));
    }

    /**
     * Permanently delete a team member, handing their work to another user.
     *
     * Every agent_id foreign key on leads, deals, tasks, activities, showings
     * and open houses cascades on delete, so the rows must be reassigned inside
     * the same transaction as the delete - dropping the user first would take
     * their entire book of business with them.
     *
     * Deletion (rather than deactivation alone) is what frees the email
     * address: users.email is globally unique and the table has no soft
     * deletes, so a deactivated member holds their address forever.
     */
    public function destroyAgent(Request $request, User $user)
    {
        $this->authorize('manageTeamMember', $user);

        if ($user->id === auth()->id()) {
            return redirect()->route('settings.index', ['tab' => 'team'])
                ->with('error', __('You cannot delete your own account.'));
        }

        $tenantId = auth()->user()->tenant_id;

        $remainingElevated = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $user->id)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin']))
            ->count();

        if ($user->roleRank() >= 2 && $remainingElevated === 0) {
            return redirect()->route('settings.index', ['tab' => 'team'])
                ->with('error', __('You cannot delete the last administrator of this workspace.'));
        }

        $validated = $request->validate([
            'reassign_to' => [
                'required',
                'integer',
                Rule::notIn([$user->id]),
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ], [
            'reassign_to.required' => __('Choose who should inherit this member\'s records.'),
            'reassign_to.not_in' => __('Records cannot be reassigned to the member being deleted.'),
        ]);

        $newOwnerId = (int) $validated['reassign_to'];

        $deleted = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->name ?? null,
        ];

        DB::transaction(function () use ($user, $newOwnerId, $tenantId) {
            // Leads go through Eloquent (not the bulk table update below) so the
            // model's updating hook regenerates each reference for the new owner.
            if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'agent_id')) {
                Lead::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('agent_id', $user->id)
                    ->chunkById(200, function ($leads) use ($newOwnerId) {
                        foreach ($leads as $lead) {
                            $lead->update(['agent_id' => $newOwnerId]);
                        }
                    });
            }

            foreach (['deals', 'tasks', 'activities', 'showings', 'open_houses'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'agent_id')) {
                    continue;
                }

                $query = DB::table($table)->where('agent_id', $user->id);

                if (Schema::hasColumn($table, 'tenant_id')) {
                    $query->where('tenant_id', $tenantId);
                }

                $query->update(['agent_id' => $newOwnerId]);
            }

            // Lead claims are transient per-agent offers, not business records.
            if (Schema::hasTable('lead_claims')) {
                DB::table('lead_claims')->where('agent_id', $user->id)->delete();
            }

            // Notifications are personal and would otherwise be orphaned.
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $user->id)
                    ->delete();
            }

            $user->delete();
        });

        AuditLog::log('agent.deleted', null, $deleted, ['reassigned_to' => $newOwnerId]);

        return redirect()->route('settings.index', ['tab' => 'team'])
            ->with('success', __('Team member deleted. Their records were reassigned and :email can be used again.', ['email' => $deleted['email']]));
    }

    /**
     * Hand ownership of the workspace to an existing Admin. Exactly one Owner
     * exists at a time: promoting the chosen Admin demotes the current Owner
     * to Admin in the same transaction.
     */
    public function transferOwnership(Request $request)
    {
        $actor = auth()->user();

        if (! $actor->isOwner()) {
            abort(403, __('Only the workspace Owner can transfer ownership.'));
        }

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::notIn([$actor->id]),
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('tenant_id', $actor->tenant_id)),
            ],
        ], [
            'user_id.not_in' => __('You are already the Owner.'),
        ]);

        $target = User::withoutGlobalScopes()
            ->where('tenant_id', $actor->tenant_id)
            ->findOrFail($validated['user_id']);

        if (! $target->hasRole('admin') || $target->isOwner()) {
            return redirect()->route('settings.index', ['tab' => 'team'])
                ->with('error', __('Ownership can only be transferred to an Admin.'));
        }

        $ownerRoleId = Role::where('name', 'owner')->value('id');
        $adminRoleId = Role::where('name', 'admin')->value('id');

        if (! $ownerRoleId || ! $adminRoleId) {
            return redirect()->route('settings.index', ['tab' => 'team'])
                ->with('error', __('The Owner and Admin roles must exist before ownership can be transferred.'));
        }

        DB::transaction(function () use ($actor, $target, $ownerRoleId, $adminRoleId) {
            $target->role_id = $ownerRoleId;
            $target->save();

            $actor->role_id = $adminRoleId;
            $actor->save();
        });

        AuditLog::log('ownership.transferred', $target, ['from' => $actor->id], ['to' => $target->id]);

        return redirect()->route('settings.index', ['tab' => 'team'])
            ->with('success', __('Ownership transferred to :name. You are now an Admin.', ['name' => $target->name]));
    }

    /**
     * Switch the tenant between wholesale and real estate mode.
     *
     * This deliberately does NOT migrate existing data. Stages and statuses are
     * stored as raw strings, and the two modes use different vocabularies, so
     * remapping is a judgement call only the operator can make. The settings
     * screen shows exactly how many records would be stranded and requires a
     * typed confirmation before this endpoint will act.
     */
    public function updateBusinessMode(Request $request)
    {
        if (! auth()->user()->isAdmin()) {
            abort(403);
        }

        $tenant = auth()->user()->tenant;

        $validated = $request->validate([
            'business_mode' => ['required', Rule::in(array_keys(BusinessModeService::MODES))],
            'confirmation' => ['required', 'string'],
        ]);

        if (strtoupper(trim($validated['confirmation'])) !== 'SWITCH') {
            return redirect()->route('settings.index', ['tab' => 'general'])
                ->with('error', __('Type SWITCH to confirm the business mode change.'));
        }

        $from = $tenant->business_mode ?? 'wholesale';
        $to = $validated['business_mode'];

        if ($from === $to) {
            return redirect()->route('settings.index', ['tab' => 'general'])
                ->with('error', __('That is already the current business mode.'));
        }

        $impact = $this->businessModeImpact($tenant);

        $tenant->update(['business_mode' => $to]);

        AuditLog::log(
            'tenant.business_mode_changed',
            $tenant,
            ['business_mode' => $from],
            ['business_mode' => $to, 'stranded_deals' => $impact['deals'], 'stranded_leads' => $impact['leads']]
        );

        return redirect()->route('settings.index', ['tab' => 'general'])
            ->with('success', __('Business mode switched to :mode. Review any deals and leads still holding stages or statuses from the previous mode.', [
                'mode' => __(BusinessModeService::MODES[$to]),
            ]));
    }

    /**
     * How many records currently hold a stage or status that does not exist in
     * the other business mode. Shown before a switch so the decision is made
     * against real numbers rather than an abstract warning.
     */
    private function businessModeImpact(Tenant $tenant): array
    {
        $current = $tenant->business_mode ?? 'wholesale';
        $target = BusinessModeService::oppositeMode($current);

        $targetStages = array_keys(BusinessModeService::getStagesForMode($target));
        $targetStatuses = array_keys(BusinessModeService::getLeadStatusesForMode($target));

        $deals = Schema::hasTable('deals')
            ? Deal::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotIn('stage', $targetStages)->count()
            : 0;

        $leads = Schema::hasTable('leads')
            ? Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotIn('status', $targetStatuses)->count()
            : 0;

        return [
            'current' => $current,
            'target' => $target,
            'deals' => $deals,
            'leads' => $leads,
        ];
    }

    public function reset2fa(User $user)
    {
        $this->authorize('manageTeamMember', $user);

        if (! $user->two_factor_enabled) {
            return redirect()->route('settings.index', ['tab' => 'team'])->with('error', __('This user does not have 2FA enabled.'));
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_enabled' => false,
            'two_factor_provider' => 'totp',
        ]);

        AuditLog::log('2fa.reset', $user);

        return redirect()->route('settings.index', ['tab' => 'team'])->with('success', __('2FA has been reset for :name.', ['name' => $user->name]));
    }

    public function updateDistribution(DistributionSettingsRequest $request)
    {
        $tenant = auth()->user()->tenant;
        $tenant->update([
            'distribution_method' => $request->distribution_method,
            'claim_window_minutes' => $request->claim_window_minutes ?? 3,
            'timezone_restriction_enabled' => $request->boolean('timezone_restriction_enabled'),
        ]);

        AuditLog::log('settings.distribution_updated', $tenant);

        $label = BusinessModeService::isRealEstate() ? 'Lead routing' : 'Distribution';

        return redirect()->route('settings.index', ['tab' => 'distribution'])->with('success', "{$label} settings updated.");
    }

    /**
     * Save the lead reference settings (fallback agent code for unassigned leads).
     */
    public function updateLeadReferenceSettings(Request $request)
    {
        $request->validate([
            'fallback_agent_code' => 'required|string|max:2|regex:/^[A-Za-z]{2}$/',
        ]);

        $tenant = auth()->user()->tenant;
        $options = $tenant->custom_options ?? [];
        $options['lead_reference'] = [
            'fallback_agent_code' => strtoupper(trim($request->fallback_agent_code)),
        ];

        $tenant->update(['custom_options' => $options]);

        AuditLog::log('settings.lead_references_updated', $tenant, ['fallback_agent_code' => $options['lead_reference']['fallback_agent_code']]);

        return redirect()->route('settings.index', ['tab' => 'lead-references'])->with('success', 'Lead reference settings updated.');
    }

    /**
     * Save how inbound portal leads without a routeable agent are handled.
     */
    public function updatePortalLeadSettings(Request $request)
    {
        $request->validate([
            'unmatched' => 'required|in:distribute,unassigned',
            'notify_admins' => 'boolean',
        ]);

        $tenant = auth()->user()->tenant;
        $options = $tenant->custom_options ?? [];
        $options['portal_leads'] = [
            'unmatched' => $request->unmatched,
            'notify_admins' => $request->boolean('notify_admins'),
        ];

        $tenant->update(['custom_options' => $options]);

        AuditLog::log('settings.portal_leads_updated', $tenant, ['unmatched' => $options['portal_leads']['unmatched']]);

        return redirect()->route('settings.index', ['tab' => 'distribution'])->with('success', 'Portal lead handling updated.');
    }

    public function updatePortalCreditsSettings(Request $request)
    {
        $tenant = auth()->user()->tenant;
        $action = $request->input('action');

        if ($action === 'balance') {
            $request->validate([
                'balance' => 'required|integer|min:0',
                'reason' => 'nullable|string|max:255',
            ]);

            app(\App\Services\Portals\BayutCreditsService::class)->adjust(
                $tenant,
                (int) $request->balance,
                $request->input('reason', 'Manual adjustment')
            );

            return redirect()->route('settings.index', ['tab' => 'portal-credits'])->with('success', 'Credit wallet updated.');
        }

        if ($action === 'cost_matrix') {
            $data = $request->validate([
                'default' => 'required|integer|min:0',
            ]);

            $matrix = ['default' => (int) $data['default']];
            foreach (\App\Models\Property::CATEGORIES as $key => $label) {
                $val = $request->input("category.{$key}");
                if ($val !== null && $val !== '') {
                    $matrix[$key] = max(0, (int) $val);
                }
            }

            $options = $tenant->custom_options ?? [];
            $options['portal_cost_matrix'] = $matrix;
            $tenant->update(['custom_options' => $options]);

            AuditLog::log('settings.portal_cost_matrix_updated', $tenant, ['matrix' => $matrix]);

            return redirect()->route('settings.index', ['tab' => 'portal-credits'])->with('success', 'Cost matrix updated.');
        }

        return back()->with('error', 'Invalid action.');
    }

    /**
     * Save the tenant-wide commission formula (default split, tier schedule and
     * how support agent shares are funded).
     */
    public function updateCommissionSettings(Request $request)
    {
        $data = $request->validate([
            'default_split_type' => 'required|in:fixed,tiered',
            'default_company_pct' => 'nullable|numeric|min:0|max:100',
            'default_agent_pct' => 'nullable|numeric|min:0|max:100',
            'tiers' => 'nullable|array',
            'tiers.*.from' => 'nullable|numeric|min:0',
            'tiers.*.max' => 'nullable|numeric|min:0',
            'tiers.*.agent_pct' => 'required|numeric|min:0|max:100',
            'default_support_funding' => 'required|in:from_agent,from_company,from_both',
        ]);

        $tenant = auth()->user()->tenant;
        $options = $tenant->custom_options ?? [];

        $companyPct = (! $request->filled('default_company_pct')) ? null : (float) $request->default_company_pct;
        $agentPct = (! $request->filled('default_agent_pct')) ? null : (float) $request->default_agent_pct;

        if ((string) $data['default_split_type'] === 'fixed') {
            if ($companyPct === null && $agentPct !== null) {
                $companyPct = max(0, 100 - $agentPct);
            } elseif ($agentPct === null && $companyPct !== null) {
                $agentPct = max(0, 100 - $companyPct);
            } else {
                $companyPct = $companyPct ?? 50;
                $agentPct = $agentPct ?? 50;
            }
        }

        $tiers = collect($request->input('tiers', []))
            ->filter(fn ($t) => ($t['agent_pct'] ?? null) !== null && ($t['agent_pct'] ?? '') !== '')
            ->map(fn ($t) => [
                'from' => blank($t['from'] ?? null) ? null : (float) $t['from'],
                'max' => blank($t['max'] ?? null) ? null : (float) $t['max'],
                'agent_pct' => (float) $t['agent_pct'],
            ])
            ->values()
            ->all();

        $options['commission_calculation'] = [
            'default_split_type' => $data['default_split_type'],
            'default_company_pct' => (string) $companyPct,
            'default_agent_pct' => (string) $agentPct,
            'tiers' => $tiers,
            'default_support_funding' => $data['default_support_funding'],
        ];

        $tenant->update(['custom_options' => $options]);

        AuditLog::log('settings.commission_formula_updated', $tenant, $options['commission_calculation']);

        return redirect()->route('settings.index', ['tab' => 'commissions'])->with('success', __('Commission formula saved.'));
    }

    /**
     * Save (or create) one member's compensation plan.
     */
    public function updateCommissionPlan(Request $request, User $user)
    {
        $data = $request->validate([
            'split_type' => 'required|in:fixed,tiered',
            'company_pct' => 'nullable|numeric|min:0|max:100',
            'agent_pct' => 'nullable|numeric|min:0|max:100',
            'pay_structure' => 'required|in:commission_only,salary_plus_commission,fixed_amount',
            'base_salary' => 'nullable|numeric|min:0',
            'fixed_amount_per_close' => 'nullable|numeric|min:0',
        ]);

        $tenant = auth()->user()->tenant;

        if ((int) $user->tenant_id !== (int) $tenant->id) {
            abort(403, __('That member does not belong to your organization.'));
        }

        $companyPct = $data['company_pct'] !== null && $data['company_pct'] !== '' ? (float) $data['company_pct'] : null;
        $agentPct = $data['agent_pct'] !== null && $data['agent_pct'] !== '' ? (float) $data['agent_pct'] : null;

        if ((string) $data['split_type'] === 'fixed') {
            if ($companyPct === null && $agentPct !== null) {
                $companyPct = max(0, 100 - $agentPct);
            } elseif ($agentPct === null && $companyPct !== null) {
                $agentPct = max(0, 100 - $companyPct);
            } else {
                $companyPct = $companyPct ?? 50;
                $agentPct = $agentPct ?? 50;
            }
        }

        \App\Models\AgentCompensation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'split_type' => $data['split_type'],
                'company_pct' => $companyPct,
                'agent_pct' => $agentPct,
                'pay_structure' => $data['pay_structure'],
                'base_salary' => blank($data['base_salary'] ?? null) ? null : $data['base_salary'],
                'fixed_amount_per_close' => blank($data['fixed_amount_per_close'] ?? null) ? null : $data['fixed_amount_per_close'],
            ]
        );

        AuditLog::log('settings.agent_compensation_updated', $user, [
            'split_type' => $data['split_type'],
            'company_pct' => $companyPct,
            'agent_pct' => $agentPct,
            'pay_structure' => $data['pay_structure'],
        ]);

        return redirect()->route('settings.index', ['tab' => 'commissions'])->with('success', __('Compensation plan saved for :name.', ['name' => $user->name]));
    }

    public function updateLeadSourceCosts(Request $request)
    {
        $request->validate([
            'costs' => 'required|array',
            'costs.*' => 'nullable|numeric|min:0',
        ]);

        $tenantId = auth()->user()->tenant_id;

        foreach ($request->costs as $source => $budget) {
            LeadSourceCost::updateOrCreate(
                ['tenant_id' => $tenantId, 'lead_source' => $source],
                ['monthly_budget' => $budget ?? 0]
            );
        }

        AuditLog::log('settings.lead_source_costs_updated');

        return redirect()->route('settings.index', ['tab' => 'lead-costs'])->with('success', 'Lead source costs updated.');
    }

    public function addLeadSource(Request $request)
    {
        $request->validate([
            'lead_source_name' => 'required|string|max:100',
        ]);

        $tenant = auth()->user()->tenant;
        $customSources = $tenant->custom_lead_sources ?? [];

        $slug = str_replace(' ', '_', strtolower(trim($request->lead_source_name)));

        // Prevent duplicates against built-in and existing custom sources
        $builtIn = array_keys(CustomFieldService::getDefaults('lead_source'));
        $existingSlugs = array_column($customSources, 'slug');

        if (in_array($slug, $builtIn) || in_array($slug, $existingSlugs)) {
            return redirect()->route('settings.index', ['tab' => 'lead-costs'])->with('error', 'That lead source already exists.');
        }

        $customSources[] = [
            'slug' => $slug,
            'name' => trim($request->lead_source_name),
        ];

        $tenant->update(['custom_lead_sources' => $customSources]);

        AuditLog::log('settings.lead_source_added', $tenant);

        return redirect()->route('settings.index', ['tab' => 'lead-costs'])->with('success', 'Lead source added successfully.');
    }

    public function removeLeadSource(Request $request)
    {
        $request->validate([
            'slug' => 'required|string',
        ]);

        $tenant = auth()->user()->tenant;
        $customSources = $tenant->custom_lead_sources ?? [];

        $customSources = array_values(array_filter($customSources, function ($source) use ($request) {
            return $source['slug'] !== $request->slug;
        }));

        $tenant->update(['custom_lead_sources' => $customSources]);

        AuditLog::log('settings.lead_source_removed', $tenant);

        return redirect()->route('settings.index', ['tab' => 'lead-costs'])->with('success', 'Lead source removed.');
    }

    public function addCustomOption(Request $request)
    {
        $request->validate([
            'field_type' => 'required|in:'.implode(',', array_keys(CustomFieldService::getFieldTypes())),
            'option_name' => 'required|string|max:100',
        ]);

        $tenant = auth()->user()->tenant;
        $result = CustomFieldService::addOption($request->field_type, $request->option_name, $tenant);

        if (! $result['success']) {
            return redirect()->route('settings.index', ['tab' => 'custom-fields'])->with('error', $result['message']);
        }

        AuditLog::log('settings.custom_option_added', $tenant);

        return redirect()->route('settings.index', ['tab' => 'custom-fields'])->with('success', 'Custom option added.');
    }

    public function removeCustomOption(Request $request)
    {
        $request->validate([
            'field_type' => 'required|string',
            'slug' => 'required|string',
        ]);

        $tenant = auth()->user()->tenant;
        $removed = CustomFieldService::removeOption($request->field_type, $request->slug, $tenant);

        if (! $removed) {
            return redirect()->route('settings.index', ['tab' => 'custom-fields'])->with('error', 'System defaults cannot be removed.');
        }

        AuditLog::log('settings.custom_option_removed', $tenant);

        return redirect()->route('settings.index', ['tab' => 'custom-fields'])->with('success', 'Custom option removed.');
    }

    /**
     * Store a new custom field definition.
     */
    public function storeCustomField(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'entity_type' => 'required|in:lead',
            'field_type' => 'required|in:text,textarea,number,date,select,checkbox',
            'options' => 'nullable|string|max:1000',
            'required' => 'nullable|boolean',
        ]);

        $tenant = auth()->user()->tenant;
        $slug = str_replace(' ', '_', strtolower(trim($request->name)));
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug);

        if (CustomFieldDefinition::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('entity_type', $request->entity_type)->where('slug', $slug)->exists()) {
            return redirect()->route('settings.index', ['tab' => 'custom-fields'])->with('error', __('A custom field with this name already exists.'));
        }

        $options = null;
        if ($request->field_type === 'select' && $request->filled('options')) {
            $options = array_map('trim', explode(',', $request->options));
            $options = array_values(array_filter($options));
        }

        $maxOrder = CustomFieldDefinition::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('entity_type', $request->entity_type)->max('sort_order') ?? 0;

        CustomFieldDefinition::create([
            'tenant_id' => $tenant->id,
            'entity_type' => $request->entity_type,
            'name' => trim($request->name),
            'slug' => $slug,
            'field_type' => $request->field_type,
            'options' => $options,
            'required' => $request->boolean('required'),
            'sort_order' => $maxOrder + 1,
        ]);

        AuditLog::log('settings.custom_field_created', $tenant);

        return redirect()->route('settings.index', ['tab' => 'custom-fields'])->with('success', __('Custom field created.'));
    }

    /**
     * Delete a custom field definition.
     */
    public function destroyCustomField(CustomFieldDefinition $customField)
    {
        if ($customField->tenant_id !== auth()->user()->tenant_id) {
            abort(403);
        }

        $customField->delete();
        AuditLog::log('settings.custom_field_deleted', auth()->user()->tenant);

        return redirect()->route('settings.index', ['tab' => 'custom-fields'])->with('success', __('Custom field removed.'));
    }

    /**
     * Generate or regenerate the tenant API key.
     */
    public function generateApiKey()
    {
        $tenant = auth()->user()->tenant;
        $tenant->update([
            'api_key' => bin2hex(random_bytes(32)),
            'api_enabled' => true,
        ]);

        AuditLog::log('settings.api_key_generated', $tenant);

        return redirect()->route('settings.index', ['tab' => 'api'])->with('success', 'API key generated successfully.');
    }

    /**
     * Enable or disable API access.
     */
    public function toggleApi()
    {
        $tenant = auth()->user()->tenant;
        $tenant->update([
            'api_enabled' => ! $tenant->api_enabled,
        ]);

        AuditLog::log('settings.api_toggled', $tenant);

        $status = $tenant->api_enabled ? 'enabled' : 'disabled';

        return redirect()->route('settings.index', ['tab' => 'api'])->with('success', "API access {$status}.");
    }

    /**
     * Update AI configuration settings.
     */
    public function updateAiSettings(Request $request)
    {
        $request->validate([
            'ai_provider' => 'required|in:openai,anthropic,gemini,ollama,custom',
            'ai_api_key' => 'nullable|string|max:500',
            'ai_model' => 'nullable|string|max:100',
            'ai_model_manual' => 'nullable|string|max:100',
            'ai_ollama_url' => 'nullable|string|max:255',
            'ai_custom_url' => 'nullable|string|max:255',
        ]);

        $tenant = auth()->user()->tenant;

        // Use whichever model field was active (dropdown or manual input)
        $model = $request->ai_model ?: ($request->ai_model_manual ?: null);

        $data = [
            'ai_provider' => $request->ai_provider,
            'ai_model' => $model,
            'ai_ollama_url' => $request->ai_ollama_url ?: null,
            'ai_custom_url' => $request->ai_custom_url ?: null,
            'ai_enabled' => true,
        ];

        // Only update API key if provided (don't clear existing key on empty submit)
        if ($request->filled('ai_api_key')) {
            $data['ai_api_key'] = $request->ai_api_key;
        }

        $tenant->update($data);

        AuditLog::log('settings.ai_updated', $tenant);

        return redirect()->route('settings.index', ['tab' => 'ai'])->with('success', 'AI settings updated.');
    }

    /**
     * Enable or disable AI features.
     */
    public function toggleAi()
    {
        $tenant = auth()->user()->tenant;
        $tenant->update(['ai_enabled' => ! $tenant->ai_enabled]);

        AuditLog::log('settings.ai_toggled', $tenant);

        $status = $tenant->ai_enabled ? 'enabled' : 'disabled';

        return redirect()->route('settings.index', ['tab' => 'ai'])->with('success', "AI features {$status}.");
    }

    public function toggleAiBriefings()
    {
        $tenant = auth()->user()->tenant;
        $tenant->update(['ai_briefings_enabled' => ! $tenant->ai_briefings_enabled]);

        $status = $tenant->ai_briefings_enabled ? __('enabled') : __('disabled');

        return redirect()->route('settings.index', ['tab' => 'ai'])->with('success', __('Auto AI Briefings')." {$status}.");
    }

    /**
     * Impersonate a team member for support/debugging.
     */
    public function impersonate(Request $request, User $user)
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        if ($user->tenant_id !== auth()->user()->tenant_id) {
            abort(403);
        }

        // Support staff may only step into accounts below their own rank, so an
        // Admin can never impersonate the Owner or a peer Admin.
        abort_unless(auth()->user()->outranks($user), 403);

        // Require the admin's current password for security
        $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($request->password, auth()->user()->password)) {
            return redirect()->route('settings.index', ['tab' => 'team'])->with('error', __('Incorrect password. Impersonation denied.'));
        }

        $adminId = auth()->id();
        AuditLog::log('impersonation.started', $user, null, ['admin_id' => $adminId, 'target_user_id' => $user->id]);

        session(['impersonating' => $adminId]);
        Auth::login($user);
        session()->regenerate();

        return redirect('/dashboard');
    }

    /**
     * Stop impersonation and return to the admin account.
     */
    public function stopImpersonation()
    {
        $adminId = session('impersonating');
        if ($adminId) {
            $admin = User::withoutGlobalScopes()
                ->where('id', $adminId)
                ->where('tenant_id', auth()->user()->tenant_id)
                ->first();
            if ($admin) {
                AuditLog::log('impersonation.stopped', $admin, null, ['admin_id' => $adminId]);
                Auth::login($admin);
                session()->forget('impersonating');
                session()->regenerate();
            }
        }

        return redirect()->route('settings.index');
    }

    /**
     * Show system health check information.
     */
    public function health()
    {
        $health = [
            'app_version' => config('app.version', '1.0.0'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'db_connection' => 'OK',
            'storage_writable' => is_writable(storage_path()),
            'queue_driver' => config('queue.default'),
        ];

        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $health['db_connection'] = 'FAILED: '.$e->getMessage();
        }

        // Active plugins with versions
        $health['plugins'] = Plugin::where('tenant_id', auth()->user()->tenant_id)
            ->where('is_active', true)
            ->get(['name', 'version', 'author'])
            ->toArray();

        return response()->json($health);
    }

    /**
     * Return a list of all language files with metadata.
     */
    public function getLanguages()
    {
        return response()->json(['languages' => $this->languageFileService->list()]);
    }

    /**
     * Return English keys merged with translations for a given locale.
     */
    public function getLanguageFile(Request $request, string $code)
    {
        try {
            return response()->json($this->languageFileService->get($code));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Save updated translations for a given locale.
     */
    public function saveLanguageFile(Request $request, string $code)
    {
        $request->validate([
            'translations' => 'required|array',
        ]);

        try {
            $this->languageFileService->save($code, $request->translations);

            return response()->json(['success' => true, 'message' => 'Language file saved.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Upload a new language JSON file.
     */
    public function uploadLanguageFile(Request $request)
    {
        $request->validate([
            'language_file' => 'required|file|mimes:json,txt|max:2048',
        ]);

        try {
            $this->languageFileService->upload($request->file('language_file'));

            return response()->json(['success' => true, 'message' => 'Language file uploaded successfully.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Update notification preferences for the tenant.
     */
    public function updateNotifications(Request $request)
    {
        $tenant = auth()->user()->tenant;

        $notificationTypes = [
            'lead_assigned',
            'lead_reassigned',
            'team_activity',
            'team_reassigned',
            'deal_stage_changed',
            'due_diligence_warning',
            'buyer_matched',
            'team_member_invited',
            'sequence_email',
            'lease_expiry_reminder',
            'calendar_reminders',
        ];

        $preferences = [];
        foreach ($notificationTypes as $type) {
            $preferences[$type] = $request->boolean($type);
        }

        $tenant->update(['notification_preferences' => $preferences]);

        AuditLog::log('settings.notifications_updated', $tenant);

        return redirect()->route('settings.index', ['tab' => 'notifications'])->with('success', 'Notification preferences updated.');
    }

    /**
     * Update mail/SMTP settings for the tenant.
     */
    public function updateMail(Request $request)
    {
        $request->validate([
            'mail_host' => 'nullable|string|max:255',
            'mail_port' => 'nullable|integer|min:1|max:65535',
            'mail_encryption' => 'nullable|in:tls,ssl',
            'mail_username' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:255',
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:255',
        ]);

        $tenant = auth()->user()->tenant;

        $settings = array_filter([
            'mail_host' => $request->input('mail_host'),
            'mail_port' => $request->input('mail_port'),
            'mail_encryption' => $request->input('mail_encryption'),
            'mail_username' => $request->input('mail_username'),
            'mail_password' => $request->filled('mail_password') ? encrypt($request->input('mail_password')) : null,
            'mail_from_address' => $request->input('mail_from_address'),
            'mail_from_name' => $request->input('mail_from_name'),
        ], fn ($v) => $v !== null && $v !== '');

        // If password field was left empty, keep the existing encrypted password
        if (! $request->filled('mail_password') && $tenant->mail_settings) {
            $existing = $tenant->mail_settings;
            if (! empty($existing['mail_password'])) {
                $settings['mail_password'] = $existing['mail_password'];
            }
        }

        $tenant->update(['mail_settings' => ! empty($settings) ? $settings : null]);

        AuditLog::log('settings.mail_updated', $tenant);

        return redirect()->route('settings.index', ['tab' => 'email'])->with('success', __('Email settings updated.'));
    }

    /**
     * Send a test email using current mail settings.
     */
    public function testEmail(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        $mail = $tenant->mail_settings ?? [];

        try {
            if (empty($mail['mail_host'])) {
                return response()->json([
                    'success' => false,
                    'message' => __('No SMTP host configured. Please fill in the SMTP settings and save before testing.'),
                ], 422);
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $mail['mail_host'],
                'mail.mailers.smtp.port' => $mail['mail_port'] ?? 587,
                'mail.mailers.smtp.encryption' => $mail['mail_encryption'] ?? 'tls',
                'mail.mailers.smtp.username' => $mail['mail_username'] ?? '',
                'mail.mailers.smtp.password' => $this->decryptMailPassword($mail['mail_password'] ?? ''),
                'mail.mailers.smtp.timeout' => 15,
                'mail.from.address' => $mail['mail_from_address'] ?? config('mail.from.address'),
                'mail.from.name' => $mail['mail_from_name'] ?? config('mail.from.name'),
            ]);
            app('mail.manager')->purge('smtp');

            \Illuminate\Support\Facades\Mail::raw(
                __('This is a test email from :name. Your SMTP settings are working correctly!', ['name' => $tenant->name]),
                function ($message) use ($user) {
                    $message->to($user->email)->subject(__('Test Email from :app', ['app' => config('app.name')]));
                }
            );

            return response()->json(['success' => true, 'email' => $user->email]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Test email failed', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => __('Failed to send test email. Check your SMTP host, port, and credentials.')], 422);
        }
    }

    public function storeWebhook(Request $request)
    {
        $request->validate([
            'url' => 'required|url|max:500',
            'secret' => 'nullable|string|max:100',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:lead.created,lead.updated,lead.status_changed,deal.stage_changed,activity.logged,buyer.notified,sequence.step_executed,*',
            'description' => 'nullable|string|max:255',
        ]);

        \App\Models\Webhook::create([
            'tenant_id' => auth()->user()->tenant_id,
            'url' => $request->url,
            'secret' => $request->secret,
            'events' => $request->events,
            'description' => $request->description,
        ]);

        return redirect()->route('settings.index', ['tab' => 'webhooks'])->with('success', 'Webhook created.');
    }

    public function toggleWebhook(\App\Models\Webhook $webhook)
    {
        if ($webhook->tenant_id !== auth()->user()->tenant_id) {
            abort(403);
        }
        $webhook->update([
            'is_active' => ! $webhook->is_active,
            'failure_count' => 0, // reset on manual toggle
        ]);

        return redirect()->route('settings.index', ['tab' => 'webhooks'])->with('success', 'Webhook '.($webhook->is_active ? 'enabled' : 'disabled').'.');
    }

    public function destroyWebhook(\App\Models\Webhook $webhook)
    {
        if ($webhook->tenant_id !== auth()->user()->tenant_id) {
            abort(403);
        }
        $webhook->delete();

        return redirect()->route('settings.index', ['tab' => 'webhooks'])->with('success', 'Webhook deleted.');
    }

    /**
     * Decrypt an encrypted mail password, returning the original string if decryption fails.
     */
    private function decryptMailPassword(string $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return decrypt($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Value was stored before encryption was added — return as-is
            return $value;
        }
    }

    /**
     * Factory reset: wipe database, clear files, remove lock, redirect to installer.
     */
    public function factoryReset(Request $request)
    {
        if ($request->input('confirmation') !== 'RESET') {
            return back()->with('error', __('You must type RESET to confirm the factory reset.'));
        }

        if (! $request->filled('password') || ! Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', __('Incorrect password. Factory reset denied.'));
        }

        // Allow extra time for large databases
        set_time_limit(300);

        // 1. Clear application caches
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        // 2. Delete uploaded files (preserve .gitignore)
        $publicStorage = storage_path('app/public');
        if (File::isDirectory($publicStorage)) {
            foreach (File::directories($publicStorage) as $dir) {
                File::deleteDirectory($dir);
            }
            foreach (File::files($publicStorage) as $file) {
                if ($file->getFilename() !== '.gitignore') {
                    File::delete($file->getPathname());
                }
            }
        }

        // 3. Clear session files
        $sessionsPath = storage_path('framework/sessions');
        if (File::isDirectory($sessionsPath)) {
            foreach (File::files($sessionsPath) as $file) {
                if ($file->getFilename() !== '.gitignore') {
                    File::delete($file->getPathname());
                }
            }
        }

        // 4. Drop all tables and re-run migrations
        Artisan::call('migrate:fresh', ['--force' => true]);

        // 5. Remove the installed lock file to trigger the installer
        File::delete(storage_path('installed.lock'));

        // 6. Logout and redirect to installer
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/install');
    }

    /**
     * Display the Roles & Permissions management page.
     */
    public function roles(Request $request)
    {
        $tenant = auth()->user()->tenant;
        $roles = Role::where(function ($q) use ($tenant) {
            $q->where('is_system', true)->orWhere('tenant_id', $tenant->id);
        })->with('permissions', 'users')->get();

        $permissions = Permission::orderBy('group')->orderBy('display_name')->get();
        $permissionGroups = $permissions->groupBy('group');

        // If a role is selected for editing, load it
        $selectedRole = null;
        if ($request->has('role')) {
            $selectedRole = $roles->firstWhere('id', $request->role);
        }

        return view('settings.roles', compact('roles', 'permissions', 'permissionGroups', 'selectedRole'));
    }

    /**
     * Create a new custom role.
     */
    public function createRole(Request $request)
    {
        $request->validate([
            'display_name' => 'required|string|max:100',
        ]);

        // Auto-generate slug from display name
        $name = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::ascii($request->display_name));
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        if (empty($name)) {
            $name = 'custom_role';
        }

        // Ensure uniqueness
        $baseName = $name;
        $counter = 1;
        while (Role::where('name', $name)->exists()) {
            $name = $baseName.'_'.$counter++;
        }

        $role = Role::create([
            'name' => $name,
            'display_name' => $request->display_name,
            'is_system' => false,
            'tenant_id' => auth()->user()->tenant_id,
        ]);

        AuditLog::log('role.created', $role);

        return redirect()->route('settings.roles', ['role' => $role->id])->with('success', __('Role created. Now assign permissions below.'));
    }

    /**
     * Update permissions for a role.
     */
    public function updateRolePermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        // Cannot modify the elevated system roles' permissions
        if ($role->is_system && in_array($role->name, ['owner', 'admin'], true)) {
            return redirect()->route('settings.roles')->with('error', __('Cannot modify owner or admin role permissions.'));
        }

        $role->permissions()->sync($request->permissions ?? []);

        AuditLog::log('role.permissions_updated', $role);

        return redirect()->route('settings.roles', ['role' => $role->id])->with('success', __('Permissions updated for :role.', ['role' => $role->display_name]));
    }

    /**
     * Delete a custom role.
     */
    public function deleteRole(Role $role)
    {
        if ($role->is_system) {
            return redirect()->route('settings.roles')->with('error', __('Cannot delete system roles.'));
        }

        if ($role->users()->count() > 0) {
            return redirect()->route('settings.roles')->with('error', __('Cannot delete a role that has users assigned. Reassign users first.'));
        }

        $role->permissions()->detach();
        $role->delete();

        AuditLog::log('role.deleted', $role);

        return redirect()->route('settings.roles')->with('success', __('Role deleted.'));
    }

    public function updateDashboardDefaults(Request $request)
    {
        $request->validate([
            'defaults' => 'required|array',
            'defaults.*' => 'array',
            'defaults.*.*' => 'string|in:'.implode(',', array_keys(\App\Services\DashboardWidgetService::WIDGETS)),
        ]);

        // Only accept known role names as keys
        $validRoles = \App\Models\Role::pluck('name')->all();
        $widgetKeys = array_keys(\App\Services\DashboardWidgetService::WIDGETS);
        $sanitized = [];
        foreach ($request->defaults as $role => $widgets) {
            if (in_array($role, $validRoles) && is_array($widgets)) {
                $sanitized[$role] = array_values(array_intersect($widgets, $widgetKeys));
            }
        }

        $tenant = auth()->user()->tenant;
        $tenant->default_dashboard_widgets = $sanitized;
        $tenant->save();

        return response()->json(['success' => true]);
    }
}
