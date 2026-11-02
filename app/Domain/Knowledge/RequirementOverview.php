<?php

namespace App\Domain\Knowledge;

use App\Models\AdmissionRequirement;
use App\Models\Institution;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What one student can know about a college's requirements for a cycle — and, just as important, what
 * they can't: key requirements with no source, conflicting sources and stale sources are unresolved.
 * Only shared sources and the student's own private sources are considered (D-016).
 */
final class RequirementOverview
{
    public function __construct(private readonly RequirementConflicts $conflicts) {}

    /**
     * Requirement types every applicant needs before calling a college "ready".
     *
     * @var array<string, string>
     */
    public const KEY_TYPES = [
        'deadline' => 'Application deadline',
        'fee' => 'Application fee',
        'test_policy' => 'Testing policy',
    ];

    /**
     * @return array{requirements: list<array<string, mixed>>, unresolved: list<string>, sources: list<array<string, mixed>>}
     */
    public function for(?Institution $institution, ?string $cycle, User $viewer): array
    {
        if ($institution === null) {
            return ['requirements' => [], 'unresolved' => ['No sources have been added for this college yet.'], 'sources' => []];
        }

        $requirements = $this->visibleRequirements($institution, $viewer);
        $sources = KnowledgeSource::query()->where('institution_id', $institution->id)->visibleTo($viewer)->latest('checked_at')->get();

        if ($sources->isEmpty()) {
            return ['requirements' => [], 'unresolved' => ['No sources have been added for this college yet.'], 'sources' => []];
        }

        $conflictGroups = $this->conflicts->groups($requirements);
        $conflictIds = RequirementConflicts::ids($conflictGroups);
        $unresolved = [];

        foreach (self::KEY_TYPES as $type => $label) {
            $forType = $requirements->where('requirement_type', $type);

            if ($forType->isEmpty()) {
                $unresolved[] = "{$label}: not found in the sources on file".($cycle ? " for {$cycle}" : '').'.';
            } elseif ($cycle !== null && $forType->every(fn (AdmissionRequirement $r) => $r->cycle_label !== null && ! $this->sameCycle($r->cycle_label, $cycle))) {
                $unresolved[] = "{$label}: the sources on file are for a different cycle than {$cycle}.";
            }
        }

        foreach ($conflictGroups as $group) {
            $values = collect($group)->map(fn (AdmissionRequirement $r) => $this->value($r).' per '.$this->sourceLabel($r->source))->unique()->implode(' vs ');
            $unresolved[] = "Sources disagree on {$group[0]->label}: {$values}.";
        }

        foreach ($sources->filter(fn (KnowledgeSource $s) => $s->status === 'active' && $s->isStale()) as $source) {
            $unresolved[] = "{$source->title} was last checked {$source->checked_at?->diffForHumans()} — it may be out of date.";
        }

        return [
            'requirements' => $requirements->map(fn (AdmissionRequirement $r) => [
                'id' => $r->id,
                'type' => $r->requirement_type,
                'label' => $r->label,
                'value' => $this->value($r),
                'deadlineDate' => $r->deadline_date?->toDateString(),
                'deadlineTime' => $r->deadline_time,
                'timezone' => $r->timezone,
                'amount' => $r->amount !== null ? (string) $r->amount : null,
                'currency' => $r->currency,
                'conditions' => $r->conditions,
                'scope' => $r->applicant_scope,
                'program' => $r->program,
                'cycle' => $r->cycle_label,
                'cycleMatches' => $cycle === null || $r->cycle_label === null || $this->sameCycle($r->cycle_label, $cycle),
                'status' => match (true) {
                    in_array($r->id, $conflictIds, true) => 'conflict',
                    (bool) $r->source?->isStale() => 'stale',
                    default => $r->review_status,
                },
                'quote' => $r->quote,
                'source' => [
                    'url' => $r->source?->url,
                    'title' => $r->source?->title,
                    'type' => $r->source?->source_type,
                    'private' => (bool) $r->source?->isPrivate(),
                    'claimedOfficial' => (bool) $r->source?->claimed_official,
                    'checkedAt' => $r->source?->checked_at?->toIso8601String(),
                ],
            ])->values()->all(),
            'unresolved' => $unresolved,
            'sources' => $sources->map(fn (KnowledgeSource $s) => [
                'id' => (string) $s->id,
                'url' => $s->url,
                'title' => $s->title,
                'type' => $s->source_type,
                'private' => $s->isPrivate(),
                'claimedOfficial' => $s->claimed_official,
                'status' => $s->status,
                'failureReason' => $s->failure_reason,
                'checkedAt' => $s->checked_at?->toIso8601String(),
                'stale' => $s->status === 'active' && $s->isStale(),
            ])->values()->all(),
        ];
    }

    /**
     * Active requirements from shared sources and the viewer's own private sources — filtered in the query.
     *
     * @return Collection<int, AdmissionRequirement>
     */
    public function visibleRequirements(Institution $institution, User $viewer): Collection
    {
        return AdmissionRequirement::query()
            ->where('institution_id', $institution->id)
            ->visibleTo($viewer)
            ->active()
            ->with('source')
            ->orderBy('requirement_type')
            ->orderBy('deadline_date')
            ->get();
    }

    public function sourceLabel(?KnowledgeSource $source): string
    {
        if ($source === null) {
            return 'an unknown source';
        }

        return $source->host.' ('.match ($source->source_type) {
            KnowledgeSource::TYPE_OFFICIAL => 'official site',
            KnowledgeSource::TYPE_REFERENCE => 'reference site',
            default => $source->claimed_official ? 'your source, marked official by you — not verified' : 'your source, not verified',
        }.')';
    }

    public function value(AdmissionRequirement $r): string
    {
        return match (true) {
            $r->deadline_date !== null => $r->deadline_date->format('j M Y').($r->deadline_time ? " {$r->deadline_time}" : '').($r->timezone ? " ({$r->timezone})" : ''),
            $r->amount !== null => trim(($r->currency ?? '').' '.rtrim(rtrim((string) $r->amount, '0'), '.')),
            default => (string) ($r->value_text ?? '—'),
        };
    }

    private function sameCycle(string $a, string $b): bool
    {
        $normalize = fn (string $value) => Str::of($value)->lower()->replace(['entry', 'intake', 'admission', 'admissions'], '')->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return $normalize($a) === $normalize($b);
    }
}
