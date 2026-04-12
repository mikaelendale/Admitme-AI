<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsOnboarded
{
    /**
     * Redirect authenticated users who have not completed onboarding.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->onboarded || $request->routeIs('onboarding')) {
            return $next($request);
        }

        return redirect()->route('onboarding');
    }
}
