<?php

namespace App\Http\Middleware;

use App\Support\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAccess
{
    /**
     * Block workspace routes when the user has no active subscription or platform pass.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || PlatformAccess::hasFullAccess($user)) {
            return $next($request);
        }

        return redirect()->route('billing.edit', ['access_required' => 1]);
    }
}
