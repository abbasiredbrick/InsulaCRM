<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correct what the availability import got wrong before this feature shipped:
 *
 *  - Relaam Commercial "SHOWROOM D" / "STORAGE D" rows imported as
 *    `apartment` become showroom / warehouse.
 *  - ICT Commercial "Full Building" (a whole commercial building, ~78k sq ft)
 *    imports as commercial_building instead of apartment.
 *  - AMS "Retail 3" (unit number speaking for itself) becomes a shop.
 *  - Maid's-room units ("2 BR + Maids room", "2BHK + MAID", "MAID'S ROOM") get
 *    `maids_room = true` and a title that carries "+ Maid".
 *
 * Marketing titles that still read as the old auto-format ("Apartment for Rent
 * in ...") are regenerated so the corrected category and the maid suffix show
 * everywhere, including the parsers and title text used in searches.
 *
 * This is a one-way data correction over the auto-generated fields; the
 * column itself is removable (down() drops it).
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = collect();

        $tokenFix = function (string $category, string $pattern) use (&$affected) {
            $ids = DB::table('properties')
                ->where('property_category', 'apartment')
                ->where(function ($q) use ($pattern) {
                    $q->where('marketing_description', 'like', $pattern)
                        ->orWhere('notes', 'like', $pattern);
                })
                ->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('properties')->whereIn('id', $ids)->update(['property_category' => $category]);
                $affected = $affected->merge($ids);
            }
        };

        $tokenFix('showroom', '%SHOWROOM%');
        $tokenFix('warehouse', '%STORAGE%');
        $tokenFix('commercial_building', '%FULL BUILDING%');

        $retail = DB::table('properties')
            ->where('property_category', 'apartment')
            ->where(function ($q) {
                $q->where('unit_no', 'like', 'retail %')
                    ->orWhere('source_unit_ref', 'like', 'retail %');
            })
            ->pluck('id');
        if ($retail->isNotEmpty()) {
            DB::table('properties')->whereIn('id', $retail)->update(['property_category' => 'shop']);
            $affected = $affected->merge($retail);
        }

        // Maid's room: any property whose marketing copy mentions it. The
        // pattern keys on the marketing text, never on arbitrary prose.
        $maidIds = DB::table('properties')
            ->where(function ($q) {
                $q->where('marketing_description', 'like', '%maid%')
                    ->orWhere('marketing_title', 'like', '%maid%')
                    ->orWhere('notes', 'like', '%maid%');
            })
            ->pluck('id');
        if ($maidIds->isNotEmpty()) {
            DB::table('properties')->whereIn('id', $maidIds)->update(['maids_room' => true]);
        }

        // Regenerate the auto-format titles so a corrected category reads
        // correctly ("Showroom for Rent in 7274A") and maid units carry
        // "+ Maid". Only titles still in the machine format are touched;
        // hand-written headline copy is left alone.
        $rebuildIds = $affected->merge($maidIds)->unique();
        $rebuild = DB::table('properties')
            ->whereIn('id', $rebuildIds)
            ->where('marketing_title', 'like', '% for Rent in %')
            ->get();

        $labels = \App\Models\Property::CATEGORIES;
        foreach ($rebuild as $row) {
            $where = $row->sub_community ?: ($row->community ?: '');
            if ($where === '') {
                continue;
            }
            $size = '';
            if ($row->bedrooms !== null) {
                $size = ((int) $row->bedrooms === 0 ? 'Studio' : (int) $row->bedrooms.'BR');
                if ($row->maids_room) {
                    $size .= ' + Maid';
                }
            }
            $category = $labels[$row->property_category] ?? ucwords(str_replace('_', ' ', (string) $row->property_category));
            $title = trim(($size !== '' ? $size.' ' : '')."{$category} for Rent in {$where}");
            DB::table('properties')->where('id', $row->id)->update(['marketing_title' => $title]);
        }
    }

    public function down(): void
    {
        // Approximate revert for the category correction: the rows we moved
        // away from 'apartment' now carry a non-apartment title, so those whose
        // marketing text still names the commercial type go back to apartment.
        $ids = DB::table('properties')
            ->whereNotIn('property_category', ['apartment'])
            ->where('marketing_title', 'like', '% for Rent in %')
            ->where(function ($q) {
                $q->where('marketing_description', 'like', '%SHOWROOM%')
                    ->orWhere('marketing_description', 'like', '%STORAGE%')
                    ->orWhere('marketing_description', 'like', '%FULL BUILDING%')
                    ->orWhere('unit_no', 'like', 'retail %')
                    ->orWhere('source_unit_ref', 'like', 'retail %');
            })
            ->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('properties')->whereIn('id', $ids)->update(['property_category' => 'apartment']);
        }
    }
};
