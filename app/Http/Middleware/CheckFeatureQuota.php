<?php

namespace App\Http\Middleware;

use App\Support\FeatureQuotaManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureQuota
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $manager = app(FeatureQuotaManager::class);

        if (! $manager->canUse($user, $feature)) {
            $summary = $manager->featureStatus($user, $feature);

            $accept = (string) $request->header('Accept', '');
            if ($request->expectsJson() || str_contains($accept, 'application/json')) {
                return response()->json([
                    'message' => 'Feature limit reached.',
                    'quota' => $summary,
                ], 429);
            }

            return back()->with('quota_exhausted', $summary);
        }

        return $next($request);
    }
}
