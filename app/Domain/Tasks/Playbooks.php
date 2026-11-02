<?php

namespace App\Domain\Tasks;

/**
 * Ready-made plans the student can start from the task panel. They go through the same validation and
 * orchestrator as a plan the mentor writes.
 */
final class Playbooks
{
    public const AFFORDABLE_SHORTLIST = 'affordable-shortlist';

    /**
     * @return array{goal: string, steps: list<array<string, mixed>>}|null
     */
    public static function get(string $name, ?string $criteria = null): ?array
    {
        return match ($name) {
            self::AFFORDABLE_SHORTLIST => [
                'goal' => 'Research affordable options and prepare a shortlist',
                'steps' => [
                    ['key' => 'research', 'type' => 'college_research', 'input' => ['criteria' => $criteria ?: 'colleges that fit my majors, destinations and budget', 'count' => 5]],
                    ['key' => 'affordability', 'type' => 'affordability_check', 'depends_on' => ['research']],
                    ['key' => 'proposal', 'type' => 'propose_shortlist', 'input' => ['max_items' => 6], 'depends_on' => ['affordability']],
                    ['key' => 'refresh', 'type' => 'refresh_sources', 'input' => ['max_sources' => 5]],
                ],
            ],
            default => null,
        };
    }
}
