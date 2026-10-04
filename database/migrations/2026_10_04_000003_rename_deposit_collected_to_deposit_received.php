<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the leasing money stage and adds the revenue-realisation ladder.
 *
 * `deposit_collected` conflated two independent facts — the landlord received the
 * deposit and the broker received the commission. They are separated now, because
 * a deposit paid with no commission paid is exactly the case where the broker is
 * exposed and the deal is not revenue:
 *
 *   deposit_received -> commission_received -> deal_won -> rent_paid
 *   -> tawtheeq_ejari -> move_in_permit -> moved_in -> deal_locked
 *
 * `deal_won` is the point the money is real: it is what replaces `closed_won` for
 * leasing, and it is set automatically when the commission is confirmed rather
 * than chosen by an agent.
 *
 * The rename is applied to both tables that store a leasing stage key. Renaming
 * the constant without moving the rows would silently strand existing leads and
 * deals on a key that no longer renders a label.
 */
return new class extends Migration
{
    /** Old key => new key. Anything absent is left untouched. */
    private const RENAMES = [
        'deposit_collected' => 'deposit_received',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            foreach (['leads', 'deals'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'stage')) {
                    continue;
                }
                DB::table($table)->where('stage', $from)->update(['stage' => $to]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            foreach (['leads', 'deals'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'stage')) {
                    continue;
                }
                DB::table($table)->where('stage', $to)->update(['stage' => $from]);
            }
        }
    }
};
