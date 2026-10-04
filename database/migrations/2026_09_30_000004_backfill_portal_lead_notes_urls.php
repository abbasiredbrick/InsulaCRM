<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move portal enquiry URLs out of `leads.notes` and into `custom_fields`, so
 * one link is shown once (as the clickable Portal Listing block) instead of
 * twice.
 *
 * Two populations, handled differently - this is the whole reason the migration
 * inspects each row rather than blanket-replacing:
 *
 *  - Property Finder: notes held nothing but the URL, duplicated from
 *    listing_url/contact_link. The notes become empty and are cleared.
 *  - Bayut: notes held the client's actual message with the URL embedded
 *    ("Hi, I am interested in your property on Bayut. Link: https://..."), and
 *    custom_fields had no listing_url at all. Blanket-stripping would delete
 *    the only copy of the link AND the message. So the URL is promoted into
 *    listing_url first, and only then removed from the prose.
 *
 * Original notes are snapshotted so down() is a real restore, not a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS lead_notes_url_backfill (
            lead_id INTEGER PRIMARY KEY,
            original_notes TEXT NULL
        )');

        // Scoped to portal-sourced leads on purpose. A hand-typed note like
        // "See https://example.com. Call after 5pm" must never be rewritten -
        // stripping the URL out of human prose leaves mangled text ("See. Call
        // after 5pm") and promotes a link the user never meant as a listing.
        $leads = DB::table('leads')
            ->whereIn('lead_source', ['bayut', 'property_finder', 'propertyfinder', 'dubizzle'])
            ->whereNotNull('notes')
            ->where(function ($query) {
                $query->where('notes', 'like', '%http://%')
                    ->orWhere('notes', 'like', '%https://%')
                    ->orWhere('notes', 'like', '%www.%');
            })
            ->get(['id', 'notes', 'custom_fields']);

        $snapshots = [];
        $updates = [];

        foreach ($leads as $lead) {
            $notes = (string) $lead->notes;
            $fields = json_decode((string) $lead->custom_fields, true);
            $fields = is_array($fields) ? $fields : [];

            $firstUrl = $this->firstUrl($notes);

            if ($firstUrl === null) {
                continue;
            }

            $listingUrl = trim((string) ($fields['listing_url'] ?? ''));

            // Nothing to promote when the URL is already in custom_fields:
            // this is the Property Finder case, where the notes are a pure
            // duplicate of the link we already render.
            if ($listingUrl === '') {
                $fields['listing_url'] = $firstUrl;
                $listingUrl = $firstUrl;
            }

            $cleaned = $this->stripUrl($notes, $firstUrl);

            if ($cleaned === $notes) {
                continue;
            }

            $snapshots[] = ['lead_id' => $lead->id, 'original_notes' => $notes];

            $updates[] = [
                'id' => $lead->id,
                'notes' => $cleaned === '' ? null : $cleaned,
                'custom_fields' => json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        if ($snapshots !== []) {
            DB::table('lead_notes_url_backfill')->insert($snapshots);
        }

        foreach ($updates as $update) {
            DB::table('leads')->where('id', $update['id'])->update([
                'notes' => $update['notes'],
                'custom_fields' => $update['custom_fields'],
            ]);
        }
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('lead_notes_url_backfill')) {
            return;
        }

        foreach (DB::table('lead_notes_url_backfill')->get() as $row) {
            DB::table('leads')->where('id', $row->lead_id)->update([
                'notes' => $row->original_notes,
            ]);
        }

        DB::statement('DROP TABLE IF EXISTS lead_notes_url_backfill');
    }

    /**
     * The first http(s)/www. URL in the text, without trailing punctuation.
     */
    private function firstUrl(string $text): ?string
    {
        if (preg_match('~(?:https?://|www\.)[^\s<>"\']+~i', $text, $m) !== 1) {
            return null;
        }

        return rtrim($m[0], '.,;:!?');
    }

    /**
     * Remove the URL from the prose, along with the label the portal wrapped
     * around it, but keep the human sentence intact.
     */
    private function stripUrl(string $notes, string $url): string
    {
        $stripped = preg_replace('~(?:\s*(?:Listing\s+Link|Link|URL|Listing)\s*:\s*)?'.preg_quote($url, '~').'~i', '', $notes);

        // Portals append a reference on the same line ("...html Reference no.:
        // 10219-GFPUOM") or on the next line ("...: <url>\r\nRef: 10219"). Tidy
        // the seams the removal leaves behind.
        $stripped = trim(preg_replace('~[ \t]{2,}~', ' ', $stripped));
        $stripped = preg_replace('~\s+([.,;:])~', '$1', $stripped);

        // "…your property on Bayut:" / "Interested in this:" - a label left
        // dangling at the end of the line by the removal.
        $stripped = preg_replace('~\b(?:Link|Listing|URL|see|on|at)\s*[:;,]\s*(?=\R|$)~iu', '', $stripped);

        // Any punctuation orphaned at the end of a line, e.g. a colon that used
        // to introduce the URL.
        $stripped = preg_replace('~[:;,]\s*(?=\R)~', '', $stripped);
        $stripped = preg_replace('~[:;,]\s*\z~', '', $stripped);

        // "See. Call after 5pm" - a one-word connector stranded by the removal.
        $stripped = preg_replace('~\b(?:see|link|check|view|refer|at|on)\s*\.\s*~iu', '', $stripped);

        $stripped = trim(preg_replace('~\n{3,}~', "\n\n", $stripped));

        return trim($stripped);
    }
};
