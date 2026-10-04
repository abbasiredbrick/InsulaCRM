<?php

namespace App\Jobs;

use App\Models\RecycledLead;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enrichment that runs after a recycled-lead import: probe the DNS mailbox
 * (MX) and, where possible, the mail server's RCPT TO verdict to confirm an
 * email address is deliverable before an agent spends time on it.
 *
 * The inline import only does fast structural checks + MX lookups on unique
 * domains; slow SMTP "does this inbox exist?" probing is queued here and never
 * blocks the upload request.
 */
class VerifyRecycledLeadEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 2;

    public function __construct(
        public RecycledLead $recycledLead,
        public ?string $tenantCountry = null,
    ) {
    }

    public function handle(): void
    {
        $email = strtolower(trim((string) $this->recycledLead->email));

        if ($email === '') {
            $this->mark('unverified');

            return;
        }

        // Relay / masked placeholders never get SMTP-probed — Apple private
        // relays and portal "whatsapp.<n>@id.bayut.com" masks are legitimate
        // hidden contacts, so they stay flagged for an agent to review.
        if ($this->isMaskedEmail($email)) {
            $this->mark('review');

            return;
        }

        $domain = strtolower(trim((string) substr($email, (int) strrpos($email, '@') + 1)));

        if ($domain === '' || ! $this->hasMxRecord($domain)) {
            $this->mark('no_mx');

            return;
        }

        // Results are cached per address so re-importing the same portal file
        // doesn't re-probe every row.
        $cacheKey = 'recycled-email-verdict:'.md5($email);
        $verdict = Cache::remember($cacheKey, now()->addDays(14), fn () => $this->smtpProbe($email, $domain));

        $this->applyVerdict($verdict, $domain);
    }

    public static function isMaskedEmail(string $email): bool
    {
        $lower = strtolower(trim($email));

        return str_contains($lower, '@privaterelay.apple')           // Apple private relay
            || str_contains($lower, '@id.bayut.com')                 // masked "whatsapp.<n>@id.bayut.com"
            || str_contains($lower, '@id.dubizzle.com')
            || str_contains($lower, '@id.propertyfinder.ae');
    }

    protected function hasMxRecord(string $domain): bool
    {
        try {
            if (function_exists('checkdnsrr')) {
                return checkdnsrr($domain, 'MX');
            }

            return count(dns_get_record($domain, DNS_MX)) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string> MX hostnames ordered by priority.
     */
    protected function mxHosts(string $domain): array
    {
        try {
            $records = dns_get_record($domain, DNS_MX);
        } catch (Throwable) {
            return [];
        }

        $hosts = [];

        foreach ($records as $record) {
            if (isset($record['target'], $record['pri'])) {
                $hosts[] = ['pri' => (int) $record['pri'], 'host' => $record['target']];
            }
        }

        usort($hosts, fn ($a, $b) => $a['pri'] <=> $b['pri']);

        return array_map(fn ($h) => $h['host'], $hosts);
    }

    /**
     * Ask the domain's mail server whether it would accept the address.
     * Returns a stable verdict: verified | undeliverable | unknown.
     */
    protected function smtpProbe(string $email, string $domain): string
    {
        $hosts = $this->mxHosts($domain);

        if ($hosts === []) {
            return 'unknown';
        }

        $context = stream_context_create(['socket' => ['timeout' => 8]]);
        $socket = null;

        foreach ($hosts as $mx) {
            $socket = @stream_socket_client("tcp://{$mx}:25", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $contexthedral);

            if (is_resource($socket)) {
                break;
            }
        }

        if (! is_resource($socket)) {
            // Port 25 is commonly blocked by ISPs / cloud hosts for outbound
            // SMTP, so a connection failure is "unknown", not a verdict.
            return 'unknown';
        }

        stream_set_timeout($socket, 8);

        $greeting = (string) fgets($socket, 1024);

        if ($greeting === '' || ! str_starts_with($greeting, '220')) {
            fclose($socket);

            return 'unknown';
        }

        $helo = gethostname() ?: 'localhost';

        fwrite($socket, "HELO {$helo}\r\n");
        fgets($socket, 1024);

        fwrite($socket, "MAIL FROM:<verify@{$domain}>\r\n");
        $from = trim((string) fgets($socket, 1024));

        fwrite($socket, "RCPT TO:<{$email}>\r\n");
        $rcpt = trim((string) fgets($socket, 1024));

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        // 550/551/553 → the server says there is no such mailbox.
        if (str_starts_with($rcpt, '550') || str_starts_with($rcpt, '551') || str_starts_with($rcpt, '553')) {
            return 'undeliverable';
        }

        // 250/251 → accepted (or the domain has a catch-all, which Google /
        // Microsoft always do), so it is "probably" deliverable.
        if (str_starts_with($rcpt, '250') || str_starts_with($rcpt, '251')) {
            return 'verified';
        }

        return 'unknown';
    }

    protected function applyVerdict(string $verdict, string $domain): void
    {
        $this->recycledLead->update([
            'email_verification_status' => $verdict,
            'email_verified_at' => now(),
        ]);

        if ($verdict === 'undeliverable') {
            $this->recycledLead->update([
                'needs_review' => true,
                'review_reason' => 'Undeliverable — no mailbox at '.$domain,
            ]);
        }

        Log::info('Recycled lead email verified', [
            'id' => $this->recycledLead->id,
            'email' => $this->recycledLead->email,
            'verdict' => $verdict,
        ]);
    }

    protected function mark(string $status): void
    {
        $this->recycledLead->update([
            'email_verification_status' => $status,
            'email_verified_at' => now(),
        ]);
    }
}
