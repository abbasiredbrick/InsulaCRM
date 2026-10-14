<?php

namespace App\Services;

use App\Models\MarketContact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Distributes unassigned cold-call contacts to the active cold-call agents in
 * round-robin batches. An interval (cron/command run) hands each agent up to
 * $batchSize pending, unassigned contacts so no single caller is flooded and
 * the whole team works a list together.
 */
class ColdCallAssignmentService
{
    public function assignBatch(Tenant $tenant, int $batchSize = 20): array
    {
        $agents = $this->rotation($tenant);

        if ($agents->isEmpty()) {
            return ['assigned' => 0, 'agents' => 0];
        }

        $assigned = 0;

        DB::transaction(function () use ($tenant, $agents, $batchSize, &$assigned) {
            $rotation = $agents->pluck('id')->values();
            $count = $rotation->count();
            $index = $this->lastIndex($tenant, $count);

            // Rotate so each interval starts where the previous one left off.
            if ($index > 0 && $count > 0) {
                $rotation = $rotation->slice($index)->merge($rotation->slice(0, $index))->values();
            }

            $handled = 0;
            foreach ($rotation as $agentId) {
                $batch = MarketContact::where('tenant_id', $tenant->id)
                    ->whereNull('called_by')
                    ->whereIn('status', ['pending', 'call_back'])
                    ->orderByRaw('COALESCE(priority, 0) DESC')
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->get();

                if ($batch->isEmpty()) {
                    break;
                }

                $ids = $batch->pluck('id')->all();
                MarketContact::whereIn('id', $ids)->update([
                    'called_by' => $agentId,
                    'assigned_at' => now(),
                ]);

                $assigned += count($ids);
                $handled++;
            }

            $this->storeIndex($tenant, ($index + $handled) % max(1, $count));
        });

        return ['assigned' => $assigned, 'agents' => $agents->count()];
    }

    /**
     * Active users holding the cold_call_agent role (primary or secondary),
     * ordered by name for a stable rotation.
     */
    protected function rotation(Tenant $tenant)
    {
        $role = fn ($q) => $q->where('name', 'cold_call_agent');

        return User::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(function ($query) use ($role) {
                $query->whereHas('role', $role)
                    ->orWhereHas('secondaryRoles', $role);
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function lastIndex(Tenant $tenant, int $count): int
    {
        $raw = (int) ($tenant->cold_call_round_robin_index ?? 0);

        return $count > 0 ? $raw % $count : 0;
    }

    protected function storeIndex(Tenant $tenant, int $index): void
    {
        $tenant->cold_call_round_robin_index = $index;
        $tenant->save();
    }
}
