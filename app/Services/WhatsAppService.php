<?php

namespace App\Services;

use App\Contracts\Integrations\WhatsAppProviderInterface;
use App\Integrations\IntegrationManager;
use App\Models\User;
use App\Notifications\Messages\WhatsAppMessage;

class WhatsAppService
{
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
}
