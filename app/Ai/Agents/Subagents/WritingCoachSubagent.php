<?php

namespace App\Ai\Agents\Subagents;

use Stringable;

/**
 * Gives honest, specific feedback on essay text the mentor passes. Feedback only: the student stays the author.
 */
class WritingCoachSubagent extends MentorSubagent
{
    public function name(): string
    {
        return 'writing_coach';
    }

    public function description(): Stringable|string
    {
        return 'Delegate essay feedback to the Writing coach sub-agent. '
            .'Pass the full essay text, the prompt it answers, the word limit and the college (if a supplement) in the task — it cannot see anything else. '
            .'It returns strengths, the most important fixes with where and why, generic or cliché lines, and how well the essay answers the prompt. It gives feedback, not a rewritten essay.';
    }

    protected function instructionFile(): string
    {
        return 'subagents/writing-coach.md';
    }
}
