<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_event_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('eventable_type', 120);
            $table->unsignedBigInteger('eventable_id');
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 20);
            $table->string('event_id', 500);
            $table->timestamps();

            $table->unique(['eventable_type', 'eventable_id', 'user_id'], 'cel_unique_event_user');
            $table->index(['eventable_type', 'eventable_id'], 'cel_eventable');
            $table->index('user_id');
            $table->index('tenant_id');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Backfill from the legacy single/dual-slot columns so existing synced
        // events keep their external ids.
        $legacy = [
            ['showings', \App\Models\Showing::class, 'agent_id', 'calendar_provider', 'calendar_event_id'],
            ['showings', \App\Models\Showing::class, 'main_owner', 'main_calendar_provider', 'main_calendar_event_id'],
            ['tasks', \App\Models\Task::class, 'agent_id', 'calendar_provider', 'calendar_event_id'],
            ['meetings', \App\Models\Meeting::class, 'agent_id', 'calendar_provider', 'calendar_event_id'],
        ];

        foreach ($legacy as [$table, $type, $userSlot, $providerCol, $eventCol]) {
            $rows = DB::table($table)
                ->whereNotNull($providerCol)
                ->whereNotNull($eventCol)
                ->get(['id', 'tenant_id', 'agent_id', 'lead_id', 'created_by']);

            foreach ($rows as $row) {
                $userId = null;

                if ($userSlot === 'main_owner') {
                    $lead = $row->lead_id ? DB::table('leads')->where('id', $row->lead_id)->first() : null;
                    $userId = $lead?->agent_id;
                } elseif ($userSlot === 'agent_id') {
                    $userId = $row->agent_id;
                }

                if (! $userId) {
                    $userId = $row->created_by;
                }

                if (! $userId) {
                    continue;
                }

                $exists = DB::table('calendar_event_links')
                    ->where('eventable_type', $type)
                    ->where('eventable_id', $row->id)
                    ->where('user_id', $userId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $meta = DB::table($table)->where('id', $row->id)->first();

                DB::table('calendar_event_links')->insert([
                    'tenant_id' => $row->tenant_id,
                    'eventable_type' => $type,
                    'eventable_id' => $row->id,
                    'user_id' => $userId,
                    'provider' => $meta->{$providerCol},
                    'event_id' => $meta->{$eventCol},
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_links');
    }
};
