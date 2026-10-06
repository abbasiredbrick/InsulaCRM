<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * Applies a tenant's own SMTP settings to the running application.
 *
 * Extracted from TenantMiddleware because not every mail leaves through an
 * authenticated request. The public offer-signing route is reached by the client
 * from their phone, with no session, so the middleware never runs and the tenant
 * would otherwise fall back to the .env defaults — which on the production box
 * is MAIL_MAILER=log. Their signed copy would silently never arrive.
 *
 * Idempotent, and a no-op when the tenant has not configured mail: the .env
 * defaults are then already correct.
 */
class TenantMailConfigurator
{
    public function apply(?Tenant $tenant): bool
    {
        if (! $tenant) {
            return false;
        }

        $mail = $tenant->mail_settings ?? [];

        if (empty($mail['mail_host'])) {
            return false;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $mail['mail_host'],
            'mail.mailers.smtp.port' => $mail['mail_port'] ?? 587,
            'mail.mailers.smtp.encryption' => $mail['mail_encryption'] ?? 'tls',
            'mail.mailers.smtp.username' => $mail['mail_username'] ?? '',
            'mail.mailers.smtp.password' => $this->decryptMailPassword($mail['mail_password'] ?? ''),
        ]);

        if (! empty($mail['mail_from_address'])) {
            config(['mail.from.address' => $mail['mail_from_address']]);
        }

        if (! empty($mail['mail_from_name'])) {
            config(['mail.from.name' => $mail['mail_from_name']]);
        }

        // Purge the cached mailer so it picks up the new config.
        app('mail.manager')->purge('smtp');

        return true;
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
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            // A password stored before encryption was introduced is still a
            // working password; refusing to send would be worse than trying it.
            return $value;
        }
    }
}
