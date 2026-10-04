<?php

namespace App\Services\Portals;

use App\Models\PortalIntegration;
use InvalidArgumentException;

class PortalLeadSourceFactory
{
    public function make(string $portal, PortalIntegration $integration): PortalLeadSource
    {
        return match ($portal) {
            'bayut', 'dubizzle' => new BayutRecycledLeadSource($integration),
            'property_finder' => new PropertyFinderRecycledLeadSource($integration),
            default => throw new InvalidArgumentException('Unsupported recycled lead portal: '.$portal),
        };
    }
}
