<?php

namespace App\Domain\Observability;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\UrlCitation;
use ReflectionClass;
use Throwable;

/**
 * Small, side-effect-free helpers for recording model runs: versions, durations, tool outcomes, cited
 * evidence and redacted errors. Nothing here stores tool arguments, tool results or message text.
 */
final class RunInstrumentation
{
    /** @var array<class-string, string|null> */
    private static array $versions = [];

    /**
     * A short hash of the class's source file: agents keep their instructions in code, so this changes
     * whenever the instructions (or anything else in the agent) change.
     */
    public static function codeVersion(string $class): ?string
    {
        if (array_key_exists($class, self::$versions)) {
            return self::$versions[$class];
        }

        try {
            $file = class_exists($class) ? (new ReflectionClass($class))->getFileName() : false;
            $version = is_string($file) && is_file($file) ? substr((string) sha1_file($file), 0, 12) : null;
        } catch (Throwable) {
            $version = null;
        }

        return self::$versions[$class] = $version;
    }

    public static function elapsedMs(int $startedHrtime): int
    {
        return (int) max(0, round((hrtime(true) - $startedHrtime) / 1_000_000));
    }

    /**
     * Tool names with the outcome each one reported — its own "status" label when it returns one (e.g.
     * pending_approval, not_proposed), otherwise ok / failed / denied.
     *
     * @param  Collection<int, ToolResult>|iterable<ToolResult>  $results
     * @return list<array{name: string, outcome: string}>
     */
    public static function toolCalls(iterable $results): array
    {
        $calls = [];

        foreach ($results as $result) {
            if (! $result instanceof ToolResult) {
                continue;
            }

            $outcome = match (true) {
                $result->denied => 'denied',
                $result->failed => 'failed',
                default => self::statusLabel($result->result) ?? 'ok',
            };

            $calls[] = ['name' => $result->name, 'outcome' => $outcome];
        }

        return array_slice($calls, 0, 50);
    }

    /**
     * Public URLs the reply cited (provider web search), at most 20.
     *
     * @return list<string>
     */
    public static function citedUrls(?Meta $meta): array
    {
        if ($meta === null) {
            return [];
        }

        return $meta->citations
            ->filter(fn ($citation) => $citation instanceof UrlCitation)
            ->map(fn (UrlCitation $citation) => mb_substr($citation->url, 0, 500))
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * Strip what must never reach logs or run records: keys, bearer tokens, extension tokens, emails.
     */
    public static function redact(string $text): string
    {
        $patterns = [
            '/\b(sk|rk|pk)-[A-Za-z0-9_\-]{8,}/' => '[redacted-key]',
            '/Bearer\s+[A-Za-z0-9._|\-]+/i' => 'Bearer [redacted]',
            '/\b\d+\|[A-Za-z0-9]{20,}\b/' => '[redacted-token]',
            '/\b(api[_-]?key|token|secret|password)(\s*[=:]\s*)\S+/i' => '$1$2[redacted]',
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[redacted-email]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    private static function statusLabel(mixed $result): ?string
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;
        $status = is_array($decoded) ? ($decoded['status'] ?? null) : null;

        return is_string($status) && preg_match('/^[a-z_]{1,40}$/', $status) === 1 ? $status : null;
    }
}
