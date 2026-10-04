<?php

namespace App\Services\Portals;

/**
 * A portal lead carries two genuinely different links, and they were being
 * conflated into one:
 *
 *  - the property page the client was looking at
 *    (bayut.com/property/details-16475863.html)
 *  - the message thread with the agent about it
 *    (bayut.com/pm/16475863/<uuid>, propertyfinder.ae/leads/v1/lead/message/…)
 *
 * Property Finder's webhook only sends the message URL (responseLink) for both,
 * so "Listing" on a PF lead used to open a chat window. Everything that writes
 * these two fields must classify first - a message URL filed under listing_url
 * is worse than no link at all, because it looks like a listing and isn't.
 */
class PortalLinkResolver
{
    /**
     * Path fragments that identify a lead/message thread rather than a
     * property page.
     */
    private const CONTACT_PATH_MARKERS = [
        '/leads/v1/lead/message/',
        '/leads/lead/message/',
        '/pm/',
        '/enquir',
        '/messages/',
        '/chat/',
    ];

    private const CONTACT_HOST_MARKERS = [
        'wa.me',
        'api.whatsapp.com',
        'web.whatsapp.com',
    ];

    /**
     * Does this URL open a conversation with the agent?
     */
    public function isContactUrl(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        if (stripos($url, 'whatsapp') !== false) {
            return true;
        }

        $lower = strtolower($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach (self::CONTACT_HOST_MARKERS as $marker) {
            if (str_contains($host, $marker)) {
                return true;
            }
        }

        foreach (self::CONTACT_PATH_MARKERS as $marker) {
            if (str_contains($path, $marker)) {
                return true;
            }
        }

        return str_contains($lower, '/pm/');
    }

    /**
     * Sort the URLs a payload offered into a listing page and a message thread.
     *
     * A URL already on the listing side wins that slot; a second URL is only
     * used to fill the other slot. Nothing is invented — if the payload only
     * ever offered a message link, that is what comes back.
     *
     * @param  array<int, string|null>  $urls
     * @return array{listing_url: ?string, contact_link: ?string}
     */
    public function classify(array $urls): array
    {
        $listing = null;
        $contact = null;

        foreach ($urls as $url) {
            $url = trim((string) $url);

            if ($url === '') {
                continue;
            }

            if ($this->isContactUrl($url)) {
                $contact ??= $url;
            } else {
                $listing ??= $url;
            }
        }

        return ['listing_url' => $listing, 'contact_link' => $contact];
    }

    /**
     * Classify, then fill a gap only where the derivation is provable.
     *
     * Bayut's message URL embeds the listing id
     * (/pm/16475863/<uuid>), and the public page for that id is
     * /property/details-16475863.html. Production confirms the pairing: leads
     * #10 and #24 share reference 10219-ZVrgZW and carry one URL of each shape.
     *
     * Property Finder is deliberately NOT derived here - its reference cannot
     * be turned into a public URL without guessing, so use resolvePfListing().
     *
     * @param  array<int, string|null>  $urls
     * @return array{listing_url: ?string, contact_link: ?string}
     */
    public function resolve(array $urls, string $portal): array
    {
        $links = $this->classify($urls);

        if ($links['listing_url'] === null && $links['contact_link'] !== null) {
            $derived = $this->deriveListingUrl($links['contact_link']);

            if ($derived !== null) {
                $links['listing_url'] = $derived;
            }
        }

        return $links;
    }

    /**
     * Ask Property Finder for the real property page behind a listing
     * reference.
     *
     * The webhook never carries a property URL, so this is the only honest way
     * to get one. The service is built per integration by the caller: it needs
     * that integration's credentials, and resolving it from the container would
     * silently hand back an EMPTY PortalIntegration (Eloquent models take no
     * constructor arguments), which fails auth and falls back to a guessed slug
     * URL - exactly the thing this class exists to prevent.
     */
    public function resolvePfListing(
        PropertyFinderPortalService $propertyFinder,
        ?string $reference,
        ?string $fallbackUrl = null
    ): ?string {
        if (trim((string) $fallbackUrl) !== '' && ! $this->isContactUrl($fallbackUrl)) {
            return $fallbackUrl;
        }

        if (blank($reference)) {
            return null;
        }

        return $propertyFinder->listingPageUrl((string) $reference);
    }

    /**
     * /pm/{listingId}/{…} -> /property/details-{listingId}.html
     */
    private function deriveListingUrl(string $contactUrl): ?string
    {
        if (preg_match('~^(https?://[^/]+)/(?:[a-z]{2}/)?pm/(\d+)~i', $contactUrl, $m) !== 1) {
            return null;
        }

        return $m[1].'/property/details-'.$m[2].'.html';
    }
}
