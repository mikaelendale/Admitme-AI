<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Replaces {@see WebFetch} for OpenAI: Laravel AI's gateway does not map WebFetch,
 * which produced empty tool entries (JSON arrays) and 400 errors from the Responses API.
 */
class AllowedDomainHttpFetchTool implements Tool
{
    protected int $invocationCount = 0;

    /**
     * @param  list<string>  $allowedDomains  Bare domains, e.g. collegeboard.org (subdomains allowed).
     */
    public function __construct(
        protected array $allowedDomains,
        protected int $maxInvocations = 3,
        protected int $maxCharsInResponse = 60000,
    ) {}

    public function description(): Stringable|string
    {
        $hosts = implode(', ', $this->allowedDomains);

        return <<<TXT
Fetch readable text from an HTTPS page on an allowlisted host (or its subdomains): {$hosts}.
Use when web search surfaced a specific official URL and you need primary-source wording. Output is truncated plain text; do not treat it as verified statistics without reading carefully.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->invocationCount >= $this->maxInvocations) {
            return json_encode([
                'ok' => false,
                'error' => 'Fetch limit reached for this agent turn.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $this->invocationCount++;

        $url = trim($request->string('url')->toString());
        if ($url === '') {
            return json_encode(['ok' => false, 'error' => 'url is required.'], JSON_UNESCAPED_UNICODE);
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return json_encode(['ok' => false, 'error' => 'Invalid URL.'], JSON_UNESCAPED_UNICODE);
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return json_encode(['ok' => false, 'error' => 'Only https URLs are allowed.'], JSON_UNESCAPED_UNICODE);
        }

        $host = strtolower((string) $parts['host']);
        if (! $this->hostMatches($host)) {
            return json_encode([
                'ok' => false,
                'error' => 'Host is not allowlisted for this tool.',
                'host' => $host,
            ], JSON_UNESCAPED_UNICODE);
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'AdmitMe-AI-CollegeResearch/1.0',
                ])
                ->get($url);

            if (! $response->successful()) {
                return json_encode([
                    'ok' => false,
                    'error' => 'HTTP error fetching URL.',
                    'status' => $response->status(),
                ], JSON_UNESCAPED_UNICODE);
            }

            $body = $response->body();
            $plain = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
            $truncated = Str::limit($plain, $this->maxCharsInResponse, '… [truncated]');

            return json_encode([
                'ok' => true,
                'url' => $url,
                'content_text' => $truncated,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false,"error":"encode_failed"}';
        } catch (Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => 'Request failed.',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->max(2048)
                ->description('Full https URL on an allowlisted domain.')
                ->required(),
        ];
    }

    protected function hostMatches(string $host): bool
    {
        foreach ($this->allowedDomains as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }

            if ($host === $allowed) {
                return true;
            }

            if (str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
