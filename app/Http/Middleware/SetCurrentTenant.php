<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            CurrentTenant::set($user->tenant_id);

            // Spatie's "teams" feature scopes roles/permissions by this same
            // tenant_id (see config/permission.php: team_foreign_key).
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        }

        return $next($request);
    }
}
