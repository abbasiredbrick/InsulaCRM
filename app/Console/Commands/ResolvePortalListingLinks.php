<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Services\Portals\PortalLinkResolver;
use App\Services\Portals\PropertyFinderPortalService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ResolvePortalListingLinks extends Command
{
    protected $signature = 'portals:resolve-listing-links
        {--force : Re-resolve a listing URL even when one is already stored}
        {--portal= : Limit to one portal (propertyfinder|bayut|all)}
        {--dry-run : Report what would change without saving}';

    protected $description = 'Give portal leads a real property page URL alongside their message-thread link.';

    private ?PropertyFinderPortalService $pf = null;

    /** lead_source values per portal alias. */
    private const SOURCES = [
        'propertyfinder' => ['property_finder', 'propertyfinder'],
        'bayut' => ['bayut'],
        'dubizzle' => ['dubizzle'],
    ];

    public function handle(PortalLinkResolver $resolver): int
    {
        $portal = strtolower((string) $this->option('portal'));
        $sources = $portal === '' || $portal === 'all'
            ? array_merge(...array_values(self::SOURCES))
            : (self::SOURCES[$portal] ?? []);

        if ($sources === []) {
            $this->error("Unknown portal [{$portal}]. Use propertyfinder, bayut or all.");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $scanned = 0;
        $changed = 0;
        $resolved = 0;

        $leads = Lead::withoutGlobalScopes()
            ->whereIn('lead_source', $sources)
            ->orderBy('id')
            ->cursor();

        foreach ($leads as $lead) {
            $scanned++;

            $fields = is_array($lead->custom_fields) ? $lead->custom_fields : [];

            // Normalise "absent" to null on both sides so the change detection
            // below compares like with like.
            $before = [
                'listing_url' => $this->blankToNull($fields['listing_url'] ?? null),
                'contact_link' => $this->blankToNull($fields['contact_link'] ?? null),
            ];

            $links = $resolver->resolve([$before['listing_url'], $before['contact_link']], (string) $lead->lead_source);

            $listing = $links['listing_url'];
            $contact = $links['contact_link'];

            // Property Finder's property page is only obtainable from the PF
            // API, so a missing one means a real lookup - not a derivation.
            // Gated on the lead's OWN portal: without this a Bayut reference
            // (10219-GFPUOM) gets looked up against Property Finder, and the
            // lead ends up with a propertyfinder.ae link.
            $isPropertyFinder = in_array(
                (string) $lead->lead_source,
                self::SOURCES['propertyfinder'],
                true
            );

            if ($isPropertyFinder && $listing === null && filled($fields['listing_reference'] ?? null)) {
                $service = $this->pfService();

                if ($service !== null) {
                    $fetched = $resolver->resolvePfListing(
                        $service,
                        (string) $fields['listing_reference']
                    );

                    if ($fetched !== null && ! $resolver->isContactUrl($fetched)) {
                        $listing = $fetched;
                        $resolved++;
                    }
                }
            }

            // Compare null-to-empty consistently: an absent key reads as ''
            // from the DB but as null from the resolver, and without this every
            // lead looks changed when nothing actually moved.
            $afterListing = ($listing === null || $listing === '') ? null : $listing;
            $afterContact = ($contact === null || $contact === '') ? null : $contact;

            if ($afterListing === $before['listing_url'] && $afterContact === $before['contact_link']) {
                continue;
            }

            $changed++;
            $this->line(sprintf(
                '  #%d  listing: %s -> %s   contact: %s -> %s',
                $lead->id,
                $this->short($before['listing_url']),
                $this->short($afterListing),
                $this->short($before['contact_link']),
                $this->short($afterContact)
            ));

            if ($dry) {
                continue;
            }

            if ($afterListing !== null) {
                $fields['listing_url'] = $afterListing;
            } else {
                unset($fields['listing_url']);
            }

            if ($afterContact !== null) {
                $fields['contact_link'] = $afterContact;
            } else {
                unset($fields['contact_link']);
            }

            $lead->custom_fields = $fields;
            $lead->save();
        }

        $this->info(sprintf(
            '%s %d of %d leads (%d listing URLs resolved via the portal API).',
            $dry ? 'Would update' : 'Updated',
            $changed,
            $scanned,
            $resolved
        ));

        return self::SUCCESS;
    }

    /**
     * The Property Finder API client, built from the tenant's own integration.
     *
     * Deliberately NOT resolved from the container: PropertyFinderPortalService
     * takes a PortalIntegration in its constructor, and the container would
     * happily supply an empty one (Eloquent models need no constructor args).
     * That fails auth, and the service then falls back to a guessed slug URL -
     * a fake listing link stored as if it were real.
     */
    private function pfService(): ?PropertyFinderPortalService
    {
        if ($this->pf !== null) {
            return $this->pf;
        }

        $integration = PortalIntegration::withoutGlobalScopes()
            ->where('portal', 'propertyfinder')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($integration === null) {
            $this->warn('No active Property Finder integration found - PF listing URLs cannot be resolved.');

            return null;
        }

        return $this->pf = new PropertyFinderPortalService($integration);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function short(?string $url): string
    {
        $url = trim((string) $url);

        return $url === '' ? '(none)' : Str::limit($url, 46);
    }
}
