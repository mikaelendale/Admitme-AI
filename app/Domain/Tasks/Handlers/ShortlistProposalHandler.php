<?php

namespace App\Domain\Tasks\Handlers;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Approvals\ProposalService;
use App\Domain\Discovery\CollegeAssessor;
use App\Domain\Tasks\TaskContext;
use App\Domain\Tasks\TaskHandler;
use App\Domain\Tasks\TaskOutcome;
use App\Models\AgentTask;
use App\Models\CollegeListSchool;
use Illuminate\Validation\ValidationException;

/**
 * Turns suggestions that fit the budget into ONE approval proposal (approve some, not others), each item
 * citing its fit-check reasons and sources. Affordability is re-checked with the student's current data
 * right before proposing, so a budget changed since the plan started is never ignored.
 */
final class ShortlistProposalHandler implements TaskHandler
{
    private const RANK = ['within_budget' => 0, 'needs_aid' => 1, 'incomplete' => 2];

    public function __construct(
        private readonly CollegeAssessor $assessor,
        private readonly ProposalService $proposals,
    ) {}

    public static function type(): string
    {
        return 'propose_shortlist';
    }

    public static function describe(): string
    {
        return 'Propose adding suggested colleges that are not over budget to the saved list, as ONE approval the student can partly accept (no AI). Each item shows its reasons and sources. Inputs: max_items (1–10, default 6). Use after college_research and affordability_check.';
    }

    public static function rules(): array
    {
        return ['max_items' => ['nullable', 'integer', 'min:1', 'max:10']];
    }

    public static function budget(): array
    {
        return ['max_model_calls' => 0, 'max_attempts' => 1];
    }

    public static function defaultTitle(array $input): string
    {
        return 'Propose a shortlist for your approval';
    }

    public function handle(AgentTask $task, TaskContext $context): TaskOutcome
    {
        $student = $context->student;
        $max = (int) ($task->input['max_items'] ?? 6);
        $current = $context->currentProfileRevision();
        $checked = $task->dependencies()->where('type', AffordabilityCheckHandler::type())->first()?->result;

        if (isset($checked['profile_revision']) && (int) $checked['profile_revision'] !== $current) {
            $context->event('revision_changed', "Your profile changed after the affordability check (revision {$checked['profile_revision']} → {$current}); affordability was re-checked with your current details before proposing.");
        }

        // Every open suggestion is a candidate — this plan's research and earlier, undecided suggestions alike.
        $suggestions = CollegeListSchool::query()
            ->where('user_id', $student->getKey())
            ->where('choice_status', CollegeChoiceStatus::Suggested)
            ->get();

        $assessments = $this->assessor->assessMany($student, $suggestions);
        $overBudget = [];
        $ranked = [];

        foreach ($suggestions as $school) {
            $assessment = $assessments[(string) $school->id];
            $status = $assessment['affordability']['status'];

            if ($status === 'over_budget') {
                $overBudget[] = $school->school_name;

                continue;
            }

            $ranked[] = [$school, $assessment, [self::RANK[$status] ?? 3, $assessment['affordability']['complete'] ? 0 : 1, -$assessment['counts']['good'], $assessment['counts']['concern'], -(int) $school->fit_score]];
        }

        usort($ranked, fn (array $a, array $b) => $a[2] <=> $b[2]);
        $chosen = array_slice($ranked, 0, $max);

        if ($chosen === []) {
            return TaskOutcome::completed($suggestions->isEmpty()
                ? 'There are no open suggestions to propose.'
                : 'None of the suggestions fit your budget, so nothing was proposed.', ['proposed' => [], 'excluded_over_budget' => $overBudget, 'profile_revision' => $current]);
        }

        $colleges = array_map(fn (array $entry) => [
            'school_name' => $entry[0]->school_name,
            'tier' => $entry[0]->tier,
            'reason' => trim(($entry[0]->why_blurb ?: 'Suggested by research.').' Affordability: '.$entry[1]['affordability']['summary']),
            'evidence' => array_values(array_map(fn (array $reason) => [
                'text' => mb_substr($reason['text'], 0, 400),
                'basis' => mb_substr($reason['basisLabel'], 0, 200),
                'url' => $reason['source']['url'] ?? null,
            ], array_slice($entry[1]['reasons'], 0, 8))),
        ], $chosen);

        try {
            $proposal = $this->proposals->proposeCollegeAdditions($student, $colleges, 'task:'.$task->getKey(), $task->conversation_id);
        } catch (ValidationException) {
            return TaskOutcome::completed('Everything that fits is already on your saved list; nothing new to propose.', ['proposed' => [], 'profile_revision' => $current]);
        }

        $names = array_map(fn (array $c) => $c['school_name'], $colleges);

        return TaskOutcome::awaitingApproval($proposal, 'Proposed adding '.count($names).' college(s) to your saved list — waiting for your approval. Nothing changes until you approve.', [
            'proposed' => $names,
            'excluded_over_budget' => $overBudget,
            'profile_revision' => $current,
        ]);
    }
}
