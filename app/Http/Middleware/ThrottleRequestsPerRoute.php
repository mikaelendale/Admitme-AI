<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` as each route declares it: N requests per M minutes for THAT route.
 *
 * Laravel keys an unnamed throttle by the user alone, so every route with `throttle:N,M` shares one counter and
 * each checks it against its own limit. The chat's frequent reads (600 a minute) then used up the 30 a minute of
 * "send a message", and the next message failed with 429 right after a long reply. Here the key also names the
 * route. Named limiters (`throttle:exports`) keep their own keys and are not affected.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    /**
     * @param  Request  $request
     */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $name = $route?->getName() ?? ($route !== null ? implode('|', $route->methods()).' '.$route->uri() : '');

        return sha1(parent::resolveRequestSignature($request).'|'.$name);
    }
}
