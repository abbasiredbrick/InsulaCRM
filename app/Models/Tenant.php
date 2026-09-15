<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $hidden = [
        'api_key',
        'ai_api_key',
        'mail_settings',
    ];

    protected $fillable = [
        'name',
        'business_mode',
        'slug',
        'email',
        'logo_path',
        'timezone',
        'currency',
        'date_format',
        'country',
        'measurement_system',
        'locale',
        'distribution_method',
        'claim_window_minutes',
        'round_robin_index',
        'timezone_restriction_enabled',
        'custom_lead_sources',
        'custom_options',
        'status',
        'api_key',
        'api_enabled',
        'ai_enabled',
        'buyer_portal_enabled',
        'buyer_portal_headline',
        'buyer_portal_description',
        'buyer_portal_config',
        'ai_provider',
        'ai_api_key',
        'ai_model',
        'ai_ollama_url',
        'ai_custom_url',
        'ai_briefings_enabled',
        'notification_preferences',
        'default_dashboard_widgets',
        'mail_settings',
        'require_2fa',
        'sso_default_driver',
        'storage_disk',
        'google_client_id',
        'google_client_secret',
        'microsoft_client_id',
        'microsoft_client_secret',
        'calendar_sync_enabled',
        'calendar_reminder_default_minutes',
    ];

    protected function casts(): array
    {
        return [
            'timezone_restriction_enabled' => 'boolean',
            'custom_lead_sources' => 'array',
            'custom_options' => 'array',
            'api_enabled' => 'boolean',
            'ai_enabled' => 'boolean',
            'ai_briefings_enabled' => 'boolean',
            'ai_api_key' => 'encrypted',
            'buyer_portal_enabled' => 'boolean',
            'buyer_portal_config' => 'array',
            'notification_preferences' => 'array',
            'default_dashboard_widgets' => 'array',
            'mail_settings' => 'array',
            'require_2fa' => 'boolean',
            'google_client_secret' => 'encrypted',
            'microsoft_client_secret' => 'encrypted',
            'calendar_sync_enabled' => 'boolean',
        ];
    }

    /**
     * Whether external Google/Microsoft calendar sync is active for this
     * tenant. When disabled, schedules stay on the system calendar and
     * reminders are delivered in-app/by email.
     */
    public function calendarSyncEnabled(): bool
    {
        return (bool) ($this->calendar_sync_enabled ?? true);
    }

    /**
     * Default lead time (minutes before an event) used when a schedule does
     * not carry its own reminder override. Null disables reminders.
     */
    public function calendarReminderDefaultMinutes(): ?int
    {
        return $this->calendar_reminder_default_minutes;
    }

    /**
     * Whether the tenant has configured OAuth credentials for a provider.
     */
    public function cloudProviderConfigured(string $provider): bool
    {
        return match ($provider) {
            'google' => $this->google_client_id && $this->google_client_secret,
            'microsoft' => $this->microsoft_client_id && $this->microsoft_client_secret,
            default => false,
        };
    }

    /**
     * OAuth application settings for the given provider, null when unset.
     */
    public function cloudClient(string $provider): ?array
    {
        return match ($provider) {
            'google' => $this->google_client_id && $this->google_client_secret
                ? ['client_id' => $this->google_client_id, 'client_secret' => $this->google_client_secret]
                : null,
            'microsoft' => $this->microsoft_client_id && $this->microsoft_client_secret
                ? ['client_id' => $this->microsoft_client_id, 'client_secret' => $this->microsoft_client_secret]
                : null,
            default => null,
        };
    }

    /**
     * Check if the tenant wants a specific notification type.
     * Defaults to true if the key is missing from preferences.
     */
    public function wantsNotification(string $type): bool
    {
        $prefs = $this->notification_preferences ?? [];

        return $prefs[$type] ?? true;
    }

    /**
     * Settings that govern the lead reference formula (e.g. fallback agent code).
     */
    public function leadReferenceSettings(): array
    {
        return array_merge(
            ['fallback_agent_code' => \App\Services\AgentCodeService::FALLBACK_CODE],
            $this->custom_options['lead_reference'] ?? []
        );
    }

    /**
     * The agent code used when a lead has no assigned agent.
     */
    public function defaultAgentCode(): string
    {
        return strtoupper($this->leadReferenceSettings()['fallback_agent_code'] ?? \App\Services\AgentCodeService::FALLBACK_CODE);
    }

    /**
     * How inbound portal leads without a routeable agent are handled.
     *
     * unmatched: "distribute" pushes them into the tenant's routing pool,
     *            "unassigned" leaves them for an agent to claim.
     * notify_admins: when true, admins get an in-app alert for unassigned leads.
     */
    public function portalLeadSettings(): array
    {
        return array_merge(
            [
                'unmatched' => 'unassigned',
                'notify_admins' => true,
            ],
            $this->custom_options['portal_leads'] ?? []
        );
    }

    /**
     * Bayut listing credits wallet. Balance is the source of truth when
     * auto-sync is off; otherwise the CRM mirrors the portal's balance via
     * the configured endpoint on every read.
     */
    public function portalWallet(): array
    {
        return array_merge(
            [
                'balance' => 0,
                'auto_sync' => false,
                'endpoint' => null,
            ],
            $this->custom_options['portal_wallet'] ?? []
        );
    }

    /**
     * Credits consumed per Bayut listing publish, optionally weighted by
     * property category (matrix[<category>] ?? matrix['default']).
     */
    public function portalCostMatrix(): array
    {
        return array_merge(
            ['default' => 1],
            $this->custom_options['portal_cost_matrix'] ?? []
        );
    }

    public function isWholesale(): bool
    {
        return ($this->business_mode ?? 'wholesale') === 'wholesale';
    }

    public function isRealEstate(): bool
    {
        return $this->business_mode === 'realestate';
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
    }

    public function buyers()
    {
        return $this->hasMany(Buyer::class);
    }
}
