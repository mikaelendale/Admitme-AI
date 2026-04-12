<?php

namespace App\Services\Ai;

/**
 * Knowledge-base JSON for AI chat: cap scalar leaves at 100 (excluding _meta subtree).
 */
final class AiContextService
{
    public const MAX_SCALAR_LEAVES = 100;

    private const META_KEY = '_meta';

    /**
     * Count string|int|float|bool|null leaves; recurse arrays. Objects as associative arrays.
     * Keys are not counted. Subtree under "_meta" is excluded from the cap count.
     */
    public function countScalarLeaves(array $data): int
    {
        return $this->countLeavesRecursive($data, true);
    }

    /**
     * @param  bool  $isRoot  when true, skip _meta subtree for counting
     */
    private function countLeavesRecursive(mixed $node, bool $isRoot = false): int
    {
        if (is_array($node)) {
            $isAssoc = array_keys($node) !== range(0, count($node) - 1);
            if ($isAssoc) {
                $total = 0;
                foreach ($node as $key => $child) {
                    if ($isRoot && $key === self::META_KEY) {
                        continue;
                    }
                    $total += $this->countLeavesRecursive($child, false);
                }

                return $total;
            }

            $total = 0;
            foreach ($node as $child) {
                $total += $this->countLeavesRecursive($child, false);
            }

            return $total;
        }

        return $this->isScalarLeaf($node) ? 1 : 0;
    }

    private function isScalarLeaf(mixed $v): bool
    {
        return $v === null
            || is_string($v)
            || is_int($v)
            || is_float($v)
            || is_bool($v);
    }

    /**
     * Deep-merge incoming over existing, then trim until scalar leaves (excl. _meta) <= max.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function mergeAndCap(array $existing, array $incoming, int $max = self::MAX_SCALAR_LEAVES): array
    {
        $merged = $this->deepMerge($existing, $incoming);
        $merged = $this->ensureMeta($merged);

        while ($this->countScalarLeaves($merged) > $max) {
            $before = $this->countScalarLeaves($merged);
            $merged = $this->trimOnePriorityBranch($merged);
            $after = $this->countScalarLeaves($merged);
            if ($after >= $before) {
                $merged = $this->truncateLongestStrings($merged, 120);
                break;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function setLastUpdated(array $data): array
    {
        $data[self::META_KEY] ??= [];
        if (! is_array($data[self::META_KEY])) {
            $data[self::META_KEY] = [];
        }
        $data[self::META_KEY]['last_updated'] = now()->toIso8601String();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $over): array
    {
        foreach ($over as $key => $val) {
            if ($key === self::META_KEY) {
                continue;
            }
            if (
                is_array($val)
                && isset($base[$key])
                && is_array($base[$key])
                && $this->isAssoc($base[$key])
                && $this->isAssoc($val)
            ) {
                $base[$key] = $this->deepMerge($base[$key], $val);
            } else {
                $base[$key] = $val;
            }
        }

        return $base;
    }

    private function isAssoc(array $a): bool
    {
        return array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ensureMeta(array $data): array
    {
        if (! isset($data[self::META_KEY]) || ! is_array($data[self::META_KEY])) {
            $data[self::META_KEY] = [];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function trimOnePriorityBranch(array $data): array
    {
        $priorityDrop = [
            'essay_ideas',
            'gaps',
            'strengths',
            'activities',
            'goals',
            'demographics',
            'academic',
        ];

        foreach ($priorityDrop as $key) {
            if (! array_key_exists($key, $data) || $key === self::META_KEY) {
                continue;
            }
            $val = $data[$key];
            if (is_array($val) && $val !== []) {
                if ($this->isAssoc($val)) {
                    $keys = array_keys($val);
                    $last = array_pop($keys);
                    if ($last !== null) {
                        unset($data[$key][$last]);
                        if ($data[$key] === []) {
                            unset($data[$key]);
                        }

                        return $data;
                    }
                }
                array_pop($data[$key]);
                if ($data[$key] === []) {
                    unset($data[$key]);
                }

                return $data;
            }
        }

        $keys = array_keys($data);
        sort($keys);
        foreach ($keys as $key) {
            if ($key === self::META_KEY) {
                continue;
            }
            unset($data[$key]);

            return $data;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function truncateLongestStrings(array $data, int $maxLen): array
    {
        $walker = function (&$node) use (&$walker, $maxLen): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $k => &$v) {
                if ($k === self::META_KEY) {
                    continue;
                }
                if (is_string($v) && strlen($v) > $maxLen) {
                    $v = substr($v, 0, $maxLen).'…';
                } elseif (is_array($v)) {
                    $walker($v);
                }
            }
        };
        $walker($data);

        return $data;
    }

    /** Stub for future chat pipeline */
    public function touchFromChat(int $userId, array $delta): void
    {
        // Queue job or merge when chat exists
    }
}
