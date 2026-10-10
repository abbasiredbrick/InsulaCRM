<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function normalize(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_strtolower($name);
    }

    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('owner_id')
                ->nullable()
                ->after('owner_email')
                ->constrained('owners')
                ->nullOnDelete();
        });

        // The owner lived as free text per unit, so the same landlord repeats in
        // every row. Collapse them into one owner per (tenant, normalized name)
        // and point the units at it — first non-empty phone/email wins. The
        // owner_name/phone/email columns stay as snapshots.
        $rows = DB::table('properties')
            ->whereNotNull('owner_name')
            ->where('owner_name', '<>', '')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'owner_name', 'owner_phone', 'owner_email']);

        $byKey = [];

        foreach ($rows as $row) {
            $key = $row->tenant_id.'|'.$this->normalize($row->owner_name);

            if (! isset($byKey[$key])) {
                $byKey[$key] = DB::table('owners')->insertGetId([
                    'tenant_id' => $row->tenant_id,
                    'name' => trim($row->owner_name),
                    'phone' => $row->owner_phone ?: null,
                    'email' => $row->owner_email ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($row->owner_phone || $row->owner_email) {
                // Richer data wins: fill fields the owner row is still missing.
                $owner = DB::table('owners')->find($byKey[$key]);
                DB::table('owners')->where('id', $byKey[$key])->update([
                    'phone' => $owner->phone ?: ($row->owner_phone ?: null),
                    'email' => $owner->email ?: ($row->owner_email ?: null),
                    'updated_at' => now(),
                ]);
            }

            if ($row->id) {
                DB::table('properties')->where('id', $row->id)->update(['owner_id' => $byKey[$key]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
