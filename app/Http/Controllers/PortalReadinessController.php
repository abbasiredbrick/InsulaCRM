<?php

namespace App\Http\Controllers;

use App\Services\Portals\BayutListingValidator;
use Illuminate\Http\Request;

class PortalReadinessController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $report = app(BayutListingValidator::class)->report();

        if ($request->filled('q')) {
            $q = $request->q;
            $report['rows'] = $report['rows']->filter(function ($row) use ($q) {
                return str_contains((string) $row['property']->display_name, $q);
            });
        }

        return view('listings.readiness', compact('report'));
    }
}