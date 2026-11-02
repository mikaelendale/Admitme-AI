<?php

namespace App\Http\Middleware;

use App\Domain\Browser\BrowserBridge;
use App\Models\ExtensionToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the linked browser extension (separate from the web session; no cookies).
 */
class AuthenticateExtension
{
    public function __construct(private readonly BrowserBridge $bridge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bridge->resolveToken($request->bearerToken());

        if ($token === null || ! in_array(ExtensionToken::ABILITY_SESSION, (array) $token->abilities, true)) {
            return response()->json(['message' => 'This extension is not linked (or its link was revoked). Link it again from AdmitMe.'], 401);
        }

        $request->attributes->set('extension_token', $token);
        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }
}
