<?php

namespace App\Services;

use App\Contracts\Integrations\WhatsAppProviderInterface;
use App\Integrations\IntegrationManager;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Support\Str;

class WhatsAppService
{
    /**
     * The "new lead assigned" WhatsApp message for an agent. Shared by the
     * initial-assignment and reassignment notifications so both carry the same
     * lead name, phone, source, the client's enquiry message and the portal
     * conversation link.
     *
     * The approved template has no URL button (it was removed to keep it
     * Utility-priced), so no button parameter is sent — a button component on
     * a buttonless template is a Meta parameter-mismatch rejection.
     */
    public function leadAssignedMessage(User $notifiable, Lead $lead): WhatsAppMessage
    {
        return WhatsAppMessage::template('new_lead_assigned')
            ->body([
                $notifiable->name,
                trim("{$lead->first_name} {$lead->last_name}") ?: __('New lead'),
                $lead->phone ?: 'N/A',
                ucwords(str_replace('_', ' ', $lead->lead_source ?? 'N/A')),
                $this->conversationMessage($lead),
                $this->conversationLink($lead),
            ]);
    }

    /**
     * Whether Keystone is able to message this agent on WhatsApp right now:
     * a number on file, an opt-in, and a real (non-log) provider configured
     * for the tenant. Used by notifications to decide whether to attach the
     * WhatsApp channel at all.
     */
    public function canSendTo(User $user): bool
    {
        if (blank($user->whatsapp_number) || ! $user->whatsapp_opt_in) {
            return false;
        }

        return $this->providerFor($user->tenant_id)->driver() !== 'log';
    }

    public function sendTemplate(User $user, WhatsAppMessage $message): bool
    {
        if (! $this->canSendTo($user)) {
            return false;
        }

        $to = $this->canonicalNumber($user);

        if ($to === null) {
            return false;
        }

        return $this->providerFor($user->tenant_id)->sendTemplate(
            $to,
            $message->templateName(),
            $message->bodyParameters(),
            $message->language(),
            $message->buttonParameter(),
        );
    }

    public function providerFor(?int $tenantId): WhatsAppProviderInterface
    {
        return app(IntegrationManager::class)->getWhatsAppProvider($tenantId);
    }

    /**
     * Digits-only international form the Cloud API expects (no leading +).
     */
    protected function canonicalNumber(User $user): ?string
    {
        $country = $user->tenant?->country;

        $canonical = app(ContactNormalizer::class)->phone($user->whatsapp_number, $country);

        if ($canonical !== null) {
            return $canonical;
        }

        $digits = preg_replace('/\D+/', '', (string) $user->whatsapp_number);

        return $digits !== '' ? $digits : null;
    }

    /**
     * The client's enquiry message, flattened to one line and clipped. Template
     * parameters reject newlines/tabs, so whitespace is collapsed, and a blank
     * message becomes "N/A" (parameters may never be empty).
     */
    protected function conversationMessage(Lead $lead): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', (string) $lead->notes));

        return $message === '' ? 'N/A' : Str::limit($message, 300);
    }

    /**
     * The portal conversation link (the Property Finder/Bayut "WhatsApp
     * conversation" thread) when one was stored, otherwise a wa.me link to the
     * lead's own number, otherwise "N/A".
     */
    protected function conversationLink(Lead $lead): string
    {
        $fields = is_array($lead->custom_fields) ? $lead->custom_fields : [];
        $link = trim((string) ($fields['contact_link'] ?? ''));

        if ($link !== '') {
            return $link;
        }

        return filled($lead->whatsapp_phone) ? 'https://wa.me/'.$lead->whatsapp_phone : 'N/A';
    }
}
