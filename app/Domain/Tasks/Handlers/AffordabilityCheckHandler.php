<?php

namespace App\Domain\Tasks\Handlers;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Discovery\CollegeAssessor;
use App\Domain\Tasks\TaskContext;
use App\Domain\Tasks\TaskHandler;
use App\Domain\Tasks\TaskOutcome;
use App\Models\AgentTask;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;

/**
 * Deterministic fit/affordability check (the Phase 6 assessor) over the student's suggested and saved
 * colleges. Blocks — rather than guessing — when there is no budget to check against.
 */
final class AffordabilityCheckHandler implements TaskHandler
{
    public function __construct(private readonly CollegeAssessor $assessor) {}

    public static function type(): string
    {
        return 'affordability_check';
    }

    public static function describe(): string
    {
        return 'Check affordability and fit for the student\'s suggested and shortlisted colleges against their budget and the sources on file (no AI). Blocks if no budget is on file. No inputs.';
    }

    public static function rules(): array
    {
        return [];
    }

    public static function budget(): array
    {
        return ['max_model_calls' => 0, 'max_attempts' => 1];
    }

    public static function defaultTitle(array $input): string
    {
        return 'Check affordability';
    }

    public function handle(AgentTask $task, TaskContext $context): TaskOutcome
    {
        $profile = OnboardingProfile::query()->where('user_id', $context->student->getKey())->first();

        if ($profile?->budget_amount === null || $profile->budget_currency === null) {
            return TaskOutcome::blocked('No yearly budget on file. Add it (amount and currency) in Profile setup, then retry this step.');
        }

        $schools = CollegeListSchool::query()
            ->where('user_id', $context->student->getKey())
            ->whereIn('choice_status', [CollegeChoiceStatus::Suggested->value, CollegeChoiceStatus::Shortlisted->value])
            ->orderBy('school_name')
            ->get();

        $assessments = $this->assessor->assessMany($context->student, $schools);
        $rows = $schools->map(fn (CollegeListSchool $s) => [
            'id' => (string) $s->id,
            'name' => $s->school_name,
            'list_status' => $s->choice_status->value,
            'affordability' => $assessments[(string) $s->id]['affordability']['status'],
            'complete' => $assessments[(string) $s->id]['affordability']['complete'],
            'summary' => $assessments[(string) $s->id]['affordability']['summary'],
        ])->values()->all();

        $counts = array_count_values(array_column($rows, 'affordability'));

        return TaskOutcome::completed(sprintf(
            'Checked %d college(s) against your budget of %s %s: %d within budget, %d need aid, %d over budget, %d incomplete (missing sources).',
            count($rows),
            $profile->budget_currency,
            number_format((int) $profile->budget_amount),
            $counts['within_budget'] ?? 0,
            $counts['needs_aid'] ?? 0,
            $counts['over_budget'] ?? 0,
            $counts['incomplete'] ?? 0,
        ), [
            'colleges' => $rows,
            'budget' => ['amount' => (int) $profile->budget_amount, 'currency' => $profile->budget_currency],
            'profile_revision' => $context->currentProfileRevision(),
        ]);
    }
}
