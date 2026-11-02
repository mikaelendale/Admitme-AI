<?php

namespace App\Domain\Knowledge;

use App\Models\AdmissionRequirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finds requirements that different sources state differently. It runs over the requirements one viewer
 * can see, so a page a student added privately only creates a conflict for that student (D-016).
 */
final class RequirementConflicts
{
    /**
     * @param  Collection<int, AdmissionRequirement>  $requirements  Active requirements visible to one viewer.
     * @return list<list<AdmissionRequirement>> Groups of requirements that disagree with each other.
     */
    public function groups(Collection $requirements): array
    {
        $groups = [];

        foreach ($requirements->groupBy(fn (AdmissionRequirement $r) => self::key($r)) as $candidates) {
            $candidates = $candidates->values();
            $conflicting = [];

            foreach ($candidates as $i => $a) {
                foreach ($candidates->slice($i + 1) as $b) {
                    // Two values on the same page are usually different contexts, not a disagreement...
                    if ($a->knowledge_source_id !== $b->knowledge_source_id && self::sameCycle($a, $b) && self::differentValue($a, $b)) {
                        $conflicting[$a->id] = $a;
                        $conflicting[$b->id] = $b;
                    }
                }
            }

            if ($conflicting !== []) {
                $groups[] = array_values($conflicting);
            }
        }

        return $groups;
    }

    /**
     * @param  list<list<AdmissionRequirement>>  $groups
     * @return list<int>
     */
    public static function ids(array $groups): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (array $group) => array_map(fn (AdmissionRequirement $r) => $r->id, $group), $groups))));
    }

    /**
     * Different pages word the same requirement differently ("Regular Decision deadline" vs "RD application
     * deadline"); compare common ones by what they are, not their exact wording.
     */
    public static function canonicalLabel(string $type, string $label): string
    {
        $text = ' '.self::normalize($label).' ';

        if ($type === 'deadline') {
            return match (true) {
                str_contains($text, ' early decision ii ') || str_contains($text, ' ed ii ') || str_contains($text, ' ed2 ') => 'deadline:ed2',
                str_contains($text, ' early decision ') || str_contains($text, ' ed ') || str_contains($text, ' ed1 ') => 'deadline:ed',
                str_contains($text, ' restrictive early action ') || str_contains($text, ' rea ') => 'deadline:rea',
                str_contains($text, ' early action ') || str_contains($text, ' ea ') => 'deadline:ea',
                str_contains($text, ' regular ') || str_contains($text, ' rd ') => 'deadline:regular',
                str_contains($text, ' priority ') => 'deadline:priority',
                default => 'deadline:'.trim($text),
            };
        }

        // A fee waiver is its own thing, not another statement of the fee...
        if ($type === 'fee' && str_contains($text, ' waiver')) {
            return 'fee:waiver';
        }

        if ($type === 'fee' && str_contains($text, ' application ')) {
            return 'fee:application';
        }

        return $type.':'.trim($text);
    }

    /**
     * Identifies "the same requirement" across sources and page versions (used for conflicts and checklists).
     */
    public static function key(AdmissionRequirement $r): string
    {
        return implode('|', [self::canonicalLabel($r->requirement_type, $r->label), self::normalize($r->program), $r->applicant_scope]);
    }

    private static function sameCycle(AdmissionRequirement $a, AdmissionRequirement $b): bool
    {
        return $a->cycle_label === null || $b->cycle_label === null || self::normalize($a->cycle_label) === self::normalize($b->cycle_label);
    }

    private static function differentValue(AdmissionRequirement $a, AdmissionRequirement $b): bool
    {
        return $a->deadline_date?->toDateString() !== $b->deadline_date?->toDateString()
            || ($a->amount !== null && $b->amount !== null && (float) $a->amount !== (float) $b->amount)
            || ($a->currency !== null && $b->currency !== null && $a->currency !== $b->currency);
    }

    private static function normalize(?string $value): string
    {
        return Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
