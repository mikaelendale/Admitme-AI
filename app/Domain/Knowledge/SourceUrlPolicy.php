<?php

namespace App\Domain\Knowledge;

use App\Models\Institution;
use App\Models\KnowledgeSource;
use Illuminate\Validation\ValidationException;

/**
 * Decides whether a URL may be fetched as admissions evidence for an institution, and how it is labelled.
 *
 * Pages on the institution's verified domain are official and pages on a known reference domain are
 * reference; both are shared knowledge. A page the student says is the official site is accepted only as
 * the student's own unverified source — a student's say-so never changes what other students see (D-016).
 * Hosts that resolve to private or loopback addresses are refused so the server cannot be pointed at
 * internal services. Development fixture hosts must be listed explicitly.
 */
final class SourceUrlPolicy
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return array{url: string, host: string, type: string}
     *
     * @throws ValidationException
     */
    public function check(string $url, Institution $institution, bool $studentSaysOfficial): array
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $hostWithPort = $port !== null ? "{$host}:{$port}" : $host;

        $extraHosts = array_map('strtolower', (array) config('admitme.knowledge.extra_hosts', []));
        $isExtra = in_array($hostWithPort, $extraHosts, true) || in_array($host, $extraHosts, true);

        if ($host === '' || ! in_array($scheme, ['https', 'http'], true) || ($scheme === 'http' && ! (config('admitme.knowledge.allow_http') && $isExtra))) {
            $this->fail('Use a full https:// link to the page.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->fail('Links with embedded credentials are not allowed.');
        }

        $ip = $isExtra ? null : $this->assertPublicHost($host);

        $type = match (true) {
            $institution->website_domain !== null && $this->onDomain($host, $institution->website_domain) => KnowledgeSource::TYPE_OFFICIAL,
            $this->onAnyDomain($host, (array) config('admitme.knowledge.reference_domains', [])) => KnowledgeSource::TYPE_REFERENCE,
            $studentSaysOfficial || $isExtra => KnowledgeSource::TYPE_STUDENT_PROVIDED,
            default => $this->fail("That site isn't {$institution->name}'s verified website or a known admissions reference site. If it is the college's official site, tick \"This is the college's official website\" — it will be kept for you only, marked as not verified."),
        };

        $normalized = $scheme.'://'.$hostWithPort.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return ['url' => $normalized, 'host' => $host, 'type' => $type, 'ip' => $ip, 'port' => $port ?? ($scheme === 'https' ? 443 : 80)];
    }

    /**
     * @return string The public address the fetch must connect to (pinned, so a second DNS answer can't redirect it).
     */
    private function assertPublicHost(string $host): string
    {
        $private = 'That address points to a private or local network and cannot be used as a source.';

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $this->fail($private);
            }

            return $host;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            $this->fail($private);
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            $this->fail('That website could not be found. Check the link.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $this->fail($private);
            }
        }

        return $addresses[0];
    }

    private function onDomain(string $host, string $domain): bool
    {
        $domain = strtolower(ltrim($domain, '.'));

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /**
     * @param  list<string>  $domains
     */
    private function onAnyDomain(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($this->onDomain($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['url' => $message]);
    }
}
