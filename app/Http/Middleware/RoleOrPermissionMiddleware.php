<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleOrPermissionMiddleware
{
    /**
     * Allow access when the user matches one of the given roles, OR holds one
     * of the given permissions on a custom (non-system) role, OR belongs to a
     * management-style custom role (e.g. "property_manager" / "pm").
     *
     * Criteria containing a "." are treated as permissions, everything else as
     * a role name (role slugs never contain dots). System roles such as
     * field_scout stay excluded even though they hold properties.view.
     */
    public function handle(Request $request, Closure $next, string ...$criteria): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        foreach ($criteria as $criterion) {
            if (! str_contains($criterion, '.')) {
                if ($user->hasRole($criterion)) {
                    return $next($request);
                }

                continue;
            }

            if ($user->role && ! $user->role->is_system && $user->hasPermission($criterion)) {
                return $next($request);
            }
        }

        // Management roles (custom non-system roles like property_manager) keep
        // access to the portfolio even when specific permissions are not set.
        if ($user->role && ! $user->role->is_system) {
            $name = $user->role->name;
            if ($name === 'pm' || $name === 'manager' || str_ends_with($name, '_manager') || str_ends_with($name, '_management')) {
                return $next($request);
            }
        }

        abort(403, 'Unauthorized action.');
    }
}
