<?php

namespace App\Services\Portals;

use App\Models\PortalCreditsLedger;
use App\Models\PortalIntegration;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class BayutCreditsService
{
    /**
     * The tenant's manual wallet balance (auto-sync off) or the value pulled
     * from the portal when auto-sync is on. Falls back to the manual balance
     * whenever the portal cannot be reached.
     */
    public function balance(Tenant $tenant): int
    {
        $wallet = $tenant->portalWallet();

        if (($wallet['auto_sync'] ?? false) && ! empty($wallet['endpoint'])) {
            $integration = PortalIntegration::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('portal', 'bayut')
                ->whereNotNull('api_token')
                ->first();

            if ($integration !== null) {
                try {
                    $response = Http::withToken($integration->api_token)
                        ->acceptJson()
                        ->timeout(10)
                        ->get($wallet['endpoint']);

                    if ($response->successful()) {
                        $body = $response->json();

                        foreach (['balance', 'credits', 'remaining', 'points'] as $key) {
                            $value = data_get($body, $key);
                            if (is_numeric($value)) {
                                return (int) $value;
                            }
                        }
                    }
                } catch (\Throwable) {
                    // Portal unreachable: present the manual mirror instead.
                }
            }
        }

        return (int) ($wallet['balance'] ?? 0);
    }

    /**
     * Credits this unit will consume when published (weighted by category).
     */
    public function costFor(Tenant $tenant, Property $property): int
    {
        $matrix = $tenant->portalCostMatrix();

        return (int) ($matrix[$property->property_category] ?? $matrix['default'] ?? 1);
    }

    public function canAfford(Tenant $tenant, Property $property, ?int $balance = null): bool
    {
        return $this->balance($tenant) >= $this->costFor($tenant, $property);
    }

    /**
     * Record a successful publish. Debits the manual wallet; when auto-sync is
     * on the portal is the source of truth, so only the ledger entry is kept.
     */
    public function consume(Tenant $tenant, Property $property, ?string $reason = null): void
    {
        $cost = $this->costFor($tenant, $property);

        $this->ledger($tenant, $property, -$cost, 'publish', $reason ?? 'Bayut listing published');

        if (! ($tenant->portalWallet()['auto_sync'] ?? false)) {
            $this->setManualBalance($tenant, max(0, (int) ($tenant->portalWallet()['balance'] ?? 0) - $cost));
        }
    }

    /**
     * Set the manual wallet to an absolute value, keeping an audit trail.
     */
    public function adjust(Tenant $tenant, int $newBalance, string $reason, ?User $user = null): void
    {
        $delta = $newBalance - (int) ($tenant->portalWallet()['balance'] ?? 0);

        $this->ledger($tenant, null, $delta, 'adjust', $reason ?: 'Manual adjustment', $user);
        $this->setManualBalance($tenant, max(0, $newBalance));
    }

    protected function setManualBalance(Tenant $tenant, int $balance): void
    {
        $options = $tenant->custom_options ?? [];
        $wallet = $tenant->portalWallet();
        $wallet['balance'] = max(0, $balance);
        $options['portal_wallet'] = $wallet;
        $tenant->update(['custom_options' => $options]);
    }

    protected function ledger(Tenant $tenant, ?Property $property, int $amount, string $type, string $reason, ?User $user = null): PortalCreditsLedger
    {
        return PortalCreditsLedger::create([
            'tenant_id'   => $tenant->id,
            'property_id' => $property?->id,
            'portal'      => 'bayut',
            'type'        => $type,
            'amount'      => $amount,
            'reason'      => $reason,
            'user_id'     => $user->id ?? auth()->id(),
        ]);
    }
}