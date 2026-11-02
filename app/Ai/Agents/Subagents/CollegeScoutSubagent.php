<?php

namespace App\Ai\Agents\Subagents;

use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * Finds colleges that fit criteria the mentor passes (budget and aid, major, location, size, applicant type)
 * and returns sourced candidates.
 */
class CollegeScoutSubagent extends MentorSubagent implements HasTools
{
    public function name(): string
    {
        return 'college_scout';
    }

    public function description(): Stringable|string
    {
        return 'Delegate finding colleges that fit the student to the College scout sub-agent. '
            .'Pass the criteria it needs in the task — intended major, budget and aid need, citizenship/international status, preferred countries or regions, size, test scores and grades if known, and colleges already on the list to skip. '
            .'It searches the web and returns 5–10 candidates with why each fits and sourced key numbers. '
            .'Afterwards, offer to add the good ones (in Agent mode, propose them for approval).';
    }

    protected function instructionFile(): string
    {
        return 'subagents/college-scout.md';
    }

    public function tools(): iterable
    {
        return [(new WebSearch)->max(8)];
    }
}
