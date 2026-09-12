<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class LeadToClientService
{
    /**
     * When a deal reaches Closed Won, convert its linked lead into a Client (Buyer).
     *
     * De-duplicates by email within the tenant; when a matching Client already
     * exists we record the win on them instead of creating a duplicate record.
     */
    public function convertFromWonDeal(Deal $deal): ?Buyer
    {
        if ($deal->stage !== 'closed_won' || ! $deal->lead_id) {
            return null;
        }

        $lead = $deal->lead;
        if (! $lead) {
            return null;
        }

        return DB::transaction(function () use ($deal, $lead) {
            $buyer = $this->buyerFor($this->contactFromLead($lead));

            Activity::create([
                'tenant_id' => $deal->tenant_id,
                'lead_id' => $deal->lead_id,
                'agent_id' => $deal->agent_id,
                'type' => 'conversion',
                'subject' => 'Lead converted to '.(\App\Services\BusinessModeService::isRealEstate() ? 'Client' : 'Buyer'),
                'body' => 'Deal "'.$deal->title.'" closed won. Converted '.$lead->full_name.' to Client #'.$buyer->id,
                'logged_at' => now(),
            ]);

            AuditLog::log('lead.converted_to_buyer', $buyer, null, [
                'deal_id' => $deal->id,
                'lead_id' => $deal->lead_id,
            ]);

            return $buyer;
        });
    }

    /**
     * Convert a lead into a Client (Buyer) — used when a rental lease is recorded.
     *
     * The same tenant-scoped de-duplication applies, so a client who closed a
     * sale earlier or leased before is never duplicated.
     */
    public function convertFromLead(Lead $lead, array $context = []): Buyer
    {
        return DB::transaction(function () use ($lead, $context) {
            $buyer = $this->buyerFor($this->contactFromLead($lead));

            $label = \App\Services\BusinessModeService::isRealEstate() ? 'Client' : 'Buyer';

            Activity::create([
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'agent_id' => $context['agent_id'] ?? $lead->agent_id ?? auth()->id(),
                'type' => 'conversion',
                'subject' => $context['subject'] ?? ('Lead converted to '.$label),
                'body' => $context['body'] ?? "Rental settled. Converted {$lead->full_name} to {$label} #{$buyer->id}",
                'logged_at' => now(),
            ]);

            AuditLog::log('lead.converted_to_buyer', $buyer, null, array_merge(
                ['lead_id' => $lead->id],
                $context['meta'] ?? [],
            ));

            return $buyer;
        });
    }

    /**
     * Convert a manually entered contact into a Client (Buyer), dedupe-safe.
     *
     * @param  array{tenant_id:int,first_name:string,last_name:string,phone:?string,email:?string,notes:?string}  $contact
     * @param  array{agent_id?:?int,subject?:string,body?:string,meta?:array}  $context
     */
    public function convertFromContact(array $contact, int $tenantId, array $context = []): Buyer
    {
        return DB::transaction(function () use ($contact, $tenantId, $context) {
            $buyer = $this->buyerFor($contact + ['tenant_id' => $tenantId]);

            $label = \App\Services\BusinessModeService::isRealEstate() ? 'Client' : 'Buyer';

            Activity::create([
                'tenant_id' => $tenantId,
                'lead_id' => null,
                'agent_id' => $context['agent_id'] ?? auth()->id(),
                'type' => 'conversion',
                'subject' => $context['subject'] ?? ('Contact converted to '.$label),
                'body' => $context['body'] ?? "{$contact['first_name']} {$contact['last_name']} converted to {$label} #{$buyer->id}",
                'logged_at' => now(),
            ]);

            AuditLog::log('lead.converted_to_buyer', $buyer, null, $context['meta'] ?? []);

            return $buyer;
        });
    }

    /**
     * Create or update (never duplicate) a Client for a contact.
     *
     * Matches by email first (case-insensitive), then by phone. When found, the
     * existing record registers another closed deal; otherwise a new Client is
     * created with one closed deal on record.
     */
    private function buyerFor(array $contact): Buyer
    {
        $existing = $this->findByContact(
            $contact['tenant_id'],
            $contact['email'] ?? null,
            $contact['phone'] ?? null,
        );

        if ($existing) {
            $existing->increment('total_deals_closed');
            $existing->update(['last_purchase_at' => now()]);

            return $existing;
        }

        return Buyer::create([
            'tenant_id' => $contact['tenant_id'],
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'phone' => $contact['phone'] ?? null,
            'email' => $contact['email'] ?? null,
            'notes' => $contact['notes'] ?? null,
            'total_deals_closed' => 1,
            'last_purchase_at' => now(),
            'buyer_score' => 0,
        ]);
    }

    private function contactFromLead(Lead $lead): array
    {
        return [
            'tenant_id' => $lead->tenant_id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'notes' => $lead->notes,
        ];
    }

    /**
     * Find an existing client in the tenant by email (case-insensitive),
     * falling back to phone.
     */
    private function findByContact(int $tenantId, ?string $email, ?string $phone): ?Buyer
    {
        if ($email) {
            $existing = Buyer::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        if ($phone) {
            return Buyer::where('tenant_id', $tenantId)
                ->where('phone', $phone)
                ->first();
        }

        return null;
    }
}
