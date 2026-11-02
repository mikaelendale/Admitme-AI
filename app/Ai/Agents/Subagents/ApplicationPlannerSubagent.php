<?php

namespace App\Ai\Agents\Subagents;

use Stringable;

/**
 * Turns the facts the mentor passes (deadlines, requirements, progress, constraints) into a dated plan.
 * It has no tools: everything it may use must be in the task.
 */
class ApplicationPlannerSubagent extends MentorSubagent
{
    public function name(): string
    {
        return 'application_planner';
    }

    public function description(): Stringable|string
    {
        return 'Delegate building a concrete, dated application plan to the Application planner sub-agent. '
            .'It has no tools and cannot see the student\'s records, so include every fact it needs in the task: today\'s date, each college with its deadlines (and whether each date is verified), requirements, what is already done, tests, recommenders, fees and the student\'s constraints. '
            .'It returns a week-by-week plan ordered by deadline, the risks, and what still needs confirming.';
    }

    protected function instructionFile(): string
    {
        return 'subagents/application-planner.md';
    }
}
