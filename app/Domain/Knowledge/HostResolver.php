<?php

namespace App\Domain\Knowledge;

/**
 * DNS lookup used by the source URL policy (replaceable in tests, which must not depend on the network).
 */
class HostResolver
{
    /**
     * @return list<string> IPv4 addresses for the host; empty when it does not resolve.
     */
    public function addresses(string $host): array
    {
        return array_values(gethostbynamel($host) ?: []);
    }
}
