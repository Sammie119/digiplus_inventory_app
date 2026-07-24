<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_super_admin && $user->tenant?->status === 'suspended') {
            abort(403, 'This account has been suspended. Please contact support.');
        }

        return $next($request);
    }
}
