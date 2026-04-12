<?php

namespace App\Services\CollegeListCouncil;

/**
 * @internal
 */
final class StructuredAgentResponseMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            /** @var array<string, mixed> $out */
            $out = $response->toArray();

            return $out;
        }

        if ($response instanceof \Traversable) {
            return iterator_to_array($response, true);
        }

        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return json_decode($json ?: '[]', true) ?: [];
    }
}
