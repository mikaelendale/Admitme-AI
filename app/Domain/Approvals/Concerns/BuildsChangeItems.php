<?php

namespace App\Domain\Approvals\Concerns;

use App\Domain\Approvals\ProposalConflictException;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Shared\StaleRevisionException;
use App\Models\CollegeListSchool;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared pieces of the "change existing records" proposals (college list, applications, document values):
 * resolving a college the agent named, the reason/evidence shown with each item, value comparison for
 * staleness, and applying each item in its own savepoint so one refused item never undoes the others.
 */
trait BuildsChangeItems
{
    /**
     * Validation for the explanation the agent attaches to a change (same shape as college additions).
     *
     * @return array<string, mixed>
     */
    protected static function explanationRules(): array
    {
        return [
            'changes.*.reason' => ['nullable', 'string', 'max:1000'],
            'changes.*.evidence' => ['nullable', 'array', 'max:8'],
            'changes.*.evidence.*.text' => ['required', 'string', 'max:400'],
            'changes.*.evidence.*.basis' => ['required', 'string', 'max:200'],
            'changes.*.evidence.*.url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * The reason and evidence an item shows the student.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    protected function explanation(array $change): array
    {
        $reason = is_string($change['reason'] ?? null) && trim($change['reason']) !== '' ? Str::limit(trim($change['reason']), 500, '…') : null;

        $evidence = array_values(array_map(fn (array $e) => [
            'text' => (string) $e['text'],
            'basis' => (string) $e['basis'],
            'url' => is_string($e['url'] ?? null) && str_starts_with($e['url'], 'http') ? $e['url'] : null,
        ], array_filter((array) ($change['evidence'] ?? []), 'is_array')));

        return ['reason' => $reason, ...($evidence !== [] ? ['evidence' => $evidence] : [])];
    }

    /**
     * Find the college the agent named: exact name or slug first, then a single partial match.
     *
     * @param  Collection<int, CollegeListSchool>  $schools  Only rows the student owns.
     * @return CollegeListSchool|list<string>|null The college, the names of several possible matches, or null.
     */
    protected function matchCollege(Collection $schools, string $name): CollegeListSchool|array|null
    {
        $needle = mb_strtolower(trim($name));
        $slug = Str::slug($name);

        if ($needle === '') {
            return null;
        }

        $exact = $schools->first(fn (CollegeListSchool $s) => mb_strtolower(trim((string) $s->school_name)) === $needle || ($slug !== '' && $s->school_slug === $slug));

        if ($exact !== null) {
            return $exact;
        }

        $partial = $schools->filter(function (CollegeListSchool $s) use ($needle, $slug) {
            $own = mb_strtolower(trim((string) $s->school_name));

            // "Purdue" names "Purdue University"; "Purdue University West Lafayette" also names it (whole words only)...
            return str_contains($own, $needle)
                || ($own !== '' && preg_match('/(^|\W)'.preg_quote($own, '/').'($|\W)/u', $needle) === 1)
                || ($slug !== '' && str_contains((string) $s->school_slug, $slug));
        })->values();

        return match ($partial->count()) {
            0 => null,
            1 => $partial->first(),
            default => $partial->pluck('school_name')->map(fn ($n) => (string) $n)->all(),
        };
    }

    /**
     * Trimmed text; blank (or the word "null") means "no value".
     */
    protected static function text(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    /**
     * Dates are YYYY-MM-DD; a full ISO timestamp is accepted and cut to its date.
     */
    protected static function date(mixed $value): mixed
    {
        $value = self::text($value);

        if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    protected function same(mixed $a, mixed $b): bool
    {
        $normalize = function (mixed $value): ?string {
            $value = self::text($value);

            return match (true) {
                $value === null => null,
                $value instanceof \BackedEnum => (string) $value->value,
                is_bool($value) => $value ? '1' : '0',
                is_scalar($value) => (string) $value,
                default => json_encode($value) ?: '',
            };
        };

        return $normalize($a) === $normalize($b);
    }

    /**
     * Apply one item's change in its own savepoint. A refused or failed item rolls back only itself.
     *
     * @param  callable(): string  $apply  Returns the item's outcome.
     */
    protected function attempt(callable $apply): string
    {
        try {
            return DB::transaction(fn () => $apply());
        } catch (StaleRevisionException|ProposalConflictException) {
            // Changed since it was proposed (a newer revision, a value already reviewed, a replaced file)...
            return 'stale';
        } catch (ValidationException) {
            return 'failed';
        } catch (DeadlockException $e) {
            // The whole decision transaction is lost; let it fail (nothing is recorded) rather than carry on...
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return 'failed';
        }
    }

    /**
     * Applied when anything was applied, queued (a source page being read) or already in place; stale when the
     * rest changed since the proposal; otherwise failed.
     *
     * @param  array<string, string>  $outcomes
     */
    protected function statusFor(array $outcomes): ProposalStatus
    {
        $values = array_values($outcomes);

        return match (true) {
            array_intersect($values, ['applied', 'queued', 'already_present']) !== [] => ProposalStatus::Applied,
            in_array('stale', $values, true) => ProposalStatus::Stale,
            default => ProposalStatus::Failed,
        };
    }

    /**
     * "Purdue University" or "3 colleges".
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected static function collegesIn(array $items): string
    {
        $colleges = array_values(array_unique(array_map(fn (array $item) => (string) ($item['college'] ?? ''), $items)));

        return count($colleges) === 1 ? $colleges[0] : count($colleges).' colleges';
    }

    protected static function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
