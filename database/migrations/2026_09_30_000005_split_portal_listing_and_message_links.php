<?php

use App\Services\Portals\PortalLinkResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Separate the property page from the message thread on existing portal leads.
 *
 * Two defects are corrected here:
 *
 *  - Property Finder sends one message URL (responseLink) for both fields, so
 *    every PF lead filed its WhatsApp thread under "listing_url" and the
 *    "Listing" link opened a chat window instead of a property.
 *  - Bayut returns /property/details-{id}.html for the page and
 *    /pm/{id}/{uuid} for the thread. Whichever arrived first was written to
 *    listing_url, so "Listing" pointed at a message thread on the leads that
 *    got the /pm/ shape.
 *
 * The migration only moves URLs that are already stored. Bayut's listing page
 * is derived from the id embedded in its /pm/ thread URL (a pairing production
 * confirms: leads #10 and #24 share reference 10219-ZVrgZW and carry one URL of
 * each shape). Property Finder's page is NOT derivable from its reference, so
 * PF listing_url is left empty for `portals:resolve-listing-links` to fill
 * from the PF API - inventing a URL here would just produce a 404.
 */
return new class extends Migration
{
    public function up(): void
    {
        $resolver = app(PortalLinkResolver::class);

        $leads = DB::table('leads')
            ->whereIn('lead_source', ['bayut', 'property_finder', 'propertyfinder', 'dubizzle'])
            ->whereNotNull('custom_fields')
            ->get(['id', 'lead_source', 'custom_fields']);

        DB::statement('CREATE TABLE IF NOT EXISTS lead_link_split_backup (
            lead_id INTEGER PRIMARY KEY,
            custom_fields TEXT NULL
        )');

        $updates = [];
        $snapshots = [];

        foreach ($leads as $lead) {
            $fields = json_decode((string) $lead->custom_fields, true);

            if (! is_array($fields)) {
                continue;
            }

            $currentListing = trim((string) ($fields['listing_url'] ?? ''));
            $currentContact = trim((string) ($fields['contact_link'] ?? ''));

            if ($currentListing === '' && $currentContact === '') {
                continue;
            }

            $links = $resolver->resolve([$currentListing, $currentContact], (string) $lead->lead_source);

            // Nothing to move, and no need to churn the row.
            if ($links['listing_url'] === $currentListing && $links['contact_link'] === $currentContact) {
                continue;
            }

            if ($links['listing_url'] !== null) {
                $fields['listing_url'] = $links['listing_url'];
            } else {
                unset($fields['listing_url']);
            }

            if ($links['contact_link'] !== null) {
                $fields['contact_link'] = $links['contact_link'];
            } else {
                unset($fields['contact_link']);
            }

            $snapshots[] = ['lead_id' => $lead->id, 'custom_fields' => $lead->custom_fields];
            $updates[] = [
                'id' => $lead->id,
                'custom_fields' => json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        if ($snapshots !== []) {
            DB::table('lead_link_split_backup')->insertOrIgnore($snapshots);
        }

        foreach ($updates as $update) {
            DB::table('leads')->where('id', $update['id'])->update(['custom_fields' => $update['custom_fields']]);
        }
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('lead_link_split_backup')) {
            return;
        }

        foreach (DB::table('lead_link_split_backup')->get() as $row) {
            DB::table('leads')->where('id', $row->lead_id)->update([
                'custom_fields' => $row->custom_fields,
            ]);
        }

        DB::statement('DROP TABLE IF EXISTS lead_link_split_backup');
    }
};
