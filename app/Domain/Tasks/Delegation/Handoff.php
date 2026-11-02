<?php

namespace App\Domain\Tasks\Delegation;

use Illuminate\Support\Str;

/**
 * The explicit delegation envelope. Built server-side for one authorized student; the specialist sees only
 * what is in it (never the parent conversation), has no tools, and every result is checked against it —
 * evidence it cites must be evidence it was given. Stored on the agent run so results are attributable.
 */
final class Handoff
{
    /**
     * @param  list<array{ref: string, text: string, source?: string|null}>  $evidence
     * @param  list<string>  $allowedTools
     * @param  array<string, int>  $budget
     */
    public function __construct(
        public readonly ?string $taskId,
        public readonly int $studentId,
        public readonly string $specialist,
        public readonly string $goal,
        public readonly int $profileRevision,
        public readonly array $evidence,
        public readonly string $contract,
        public readonly array $allowedTools = [],
        public readonly array $budget = [],
    ) {}

    /**
     * @return list<string>
     */
    public function evidenceRefs(): array
    {
        return array_column($this->evidence, 'ref');
    }

    /**
     * Keep only references the specialist was actually given.
     *
     * @return list<string>
     */
    public function knownRefs(mixed $refs): array
    {
        $known = $this->evidenceRefs();

        return array_values(array_unique(array_filter(
            array_map(fn ($ref) => is_string($ref) ? trim($ref, "[] \t") : null, (array) $refs),
            fn ($ref) => $ref !== null && in_array($ref, $known, true),
        )));
    }

    /**
     * The whole of what the specialist is told.
     */
    public function prompt(): string
    {
        $lines = ["Goal: {$this->goal}", '', 'Evidence (cite by label; nothing else is known about this student):'];

        foreach ($this->evidence as $item) {
            $lines[] = "[{$item['ref']}] ".Str::limit($item['text'], 1500, '…').(! empty($item['source']) ? " (source: {$item['source']})" : '');
        }

        $lines[] = '';
        $lines[] = "Answer in the required structure ({$this->contract}).";

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'student_id' => $this->studentId,
            'specialist' => $this->specialist,
            'goal' => $this->goal,
            'profile_revision' => $this->profileRevision,
            'evidence_refs' => $this->evidenceRefs(),
            'contract' => $this->contract,
            'allowed_tools' => $this->allowedTools,
            'budget' => $this->budget,
        ];
    }
}
