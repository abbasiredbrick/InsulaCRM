<?php

namespace App\Services\Portals;

interface PortalLeadSource
{
    public function fetchNext(array $criteria, array $cursor): array;
}
