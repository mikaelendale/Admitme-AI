<?php

namespace App\Ai\Agents\Subagents;

use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * Researches admissions facts on the web — deadlines, requirements, costs, aid, policies — preferring
 * official sources, and returns findings with links.
 */
class ResearcherSubagent extends MentorSubagent implements HasTools
{
    public function name(): string
    {
        return 'researcher';
    }

    public function description(): Stringable|string
    {
        return 'Delegate web research to the Researcher sub-agent: official deadlines, requirements, testing policies, costs, aid and other facts for one or more colleges. '
            .'Give a complete, self-contained task (college names, entry term such as Fall 2027, applicant type such as international, and exactly what to find) — it cannot see this conversation or the student\'s records. '
            .'It searches the web, prefers official sources and returns findings with links and what it could not confirm. '
            .'Prefer it over searching yourself when the research covers several colleges or several questions.';
    }

    protected function instructionFile(): string
    {
        return 'subagents/researcher.md';
    }

    public function tools(): iterable
    {
        return [(new WebSearch)->max(8)];
    }
}
