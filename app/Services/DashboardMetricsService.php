<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Aggregates closed lease metrics (rental leads that reached status closed_won).
 *
 * In real estate mode a lease is "closed" when the lead reaches status closed_won
 * with deal_type rent. The associated revenue is the admin_fee collected on the
 * linked rental unit(s). There is no Deal row for a closed lease, so the deals
 * table cannot represent it — hence these metrics.
 */
class DashboardMetricsService
{
    /**
     * Base query for closed rental leases.
     */
    public static function closedLeasesQuery(?int $agentId = null): \Illuminate\Database\Eloquent\Builder
    {
        return Lead::query()
            ->where('status', 'closed_won')
            ->where('deal_type', 'rent')
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId));
    }

    /**
     * Count of closed leases closed during a given month.
     *
     * @param  string|null  $month  'Y-m-d' or any date string; defaults to current month.
     */
    public static function closedLeasesCount(?int $agentId = null, ?string $month = null): int
    {
        if (! self::isRealEstateMode()) {
            return 0;
        }

        [$from, $to] = self::rangeForMonth($month);

        return self::closedLeasesQuery($agentId)
            ->whereBetween('updated_at', [$from, $to])
            ->count();
    }

    /**
     * Total admin_fee collected from closed leases during a given month.
     */
    public static function closedLeasesFees(?int $agentId = null, ?string $month = null): float
    {
        if (! self::isRealEstateMode()) {
            return 0.0;
        }

        [$from, $to] = self::rangeForMonth($month);

        return (float) self::closedLeasesQuery($agentId)
            ->whereBetween('updated_at', [$from, $to])
            ->with('properties:id,admin_fee')
            ->get()
            ->flatMap(fn ($lead) => $lead->properties->pluck('admin_fee'))
            ->sum();
    }

    /**
     * Number of closed leases per month for the chart (last 6 months).
     *
     * @return array<int, array{count: int, fees: float}>
     */
    public static function monthlyClosedLeases(?int $agentId = null, int $months = 6): array
    {
        if (! self::isRealEstateMode()) {
            return [];
        }

        $out = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthKey = $date->format('Y-m');

            $monthLeads = self::closedLeasesQuery($agentId)
                ->whereYear('updated_at', $date->year)
                ->whereMonth('updated_at', $date->month)
                ->with('properties:id,admin_fee')
                ->get();

            $out[$monthKey] = [
                'count' => $monthLeads->count(),
                'fees' => (float) $monthLeads->flatMap(fn ($lead) => $lead->properties->pluck('admin_fee'))->sum(),
            ];
        }

        return $out;
    }

    /**
     * Whether this tenant follows the real-estate mode (only one that uses leases).
     */
    public static function isRealEstateMode(): bool
    {
        return BusinessModeService::isRealEstate();
    }

    /**
     * Compute a between()-compatible [start, end] range from a month string.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    protected static function rangeForMonth(?string $month = null): array
    {
        $date = $month ? \Carbon\Carbon::parse($month) : now();
        $from = $date->copy()->startOfMonth();
        $to = $date->copy()->endOfMonth();

        return [$from, $to];
    }
}