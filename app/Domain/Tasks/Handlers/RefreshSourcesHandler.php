<?php

namespace App\Domain\Tasks\Handlers;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Knowledge\KnowledgeIngestionService;
use App\Domain\Tasks\TaskContext;
use App\Domain\Tasks\TaskHandler;
use App\Domain\Tasks\TaskOutcome;
use App\Models\AgentTask;
use App\Models\CollegeListSchool;
use App\Models\Institution;
use App\Models\KnowledgeSource;
use Illuminate\Validation\ValidationException;

/**
 * Re-checks pages already on file for the student's saved colleges (through the same allow-listed, pinned
 * fetch as Phase 5), stalest first. Independent of approvals, so it keeps going while a proposal waits.
 */
final class RefreshSourcesHandler implements TaskHandler
{
    public function __construct(private readonly KnowledgeIngestionService $ingestion) {}

    public static function type(): string
    {
        return 'refresh_sources';
    }

    public static function describe(): string
    {
        return 'Re-check the college pages already on file for the student\'s saved colleges, stalest first, recording any changes (no AI). Inputs: max_sources (1–10, default 5). Can run alongside other steps.';
    }

    public static function rules(): array
    {
        return ['max_sources' => ['nullable', 'integer', 'min:1', 'max:10']];
    }

    public static function budget(): array
    {
        return ['max_model_calls' => 0, 'max_attempts' => 2];
    }

    public static function defaultTitle(array $input): string
    {
        return 'Re-check college pages on file';
    }

    public function handle(AgentTask $task, TaskContext $context): TaskOutcome
    {
        $student = $context->student;
        $slugs = CollegeListSchool::query()
            ->where('user_id', $student->getKey())
            ->whereIn('choice_status', [CollegeChoiceStatus::Shortlisted->value, CollegeChoiceStatus::Applying->value])
            ->pluck('school_slug');

        $institutions = Institution::query()->whereIn('slug', $slugs)->get()->keyBy('id');

        $sources = KnowledgeSource::query()
            ->whereIn('institution_id', $institutions->keys())
            ->visibleTo($student)
            ->where('status', 'active')
            ->orderBy('checked_at')
            ->limit((int) ($task->input['max_sources'] ?? 5))
            ->get();

        if ($sources->isEmpty()) {
            return TaskOutcome::completed('No college pages on file for your saved colleges yet, so there was nothing to re-check.', ['checked' => 0]);
        }

        $changed = [];
        $unchanged = [];
        $failed = [];

        foreach ($sources as $source) {
            $context->checkpoint();
            $before = $source->current_version_id;

            try {
                $after = $this->ingestion->ingest($institutions[$source->institution_id], $source->url, $student, (bool) $source->claimed_official);
            } catch (ValidationException $e) {
                $failed[] = ($source->title ?: $source->host).': '.collect($e->errors())->flatten()->first();

                continue;
            }

            match (true) {
                $after->status === 'failed' => $failed[] = ($source->title ?: $source->host).': '.$after->failure_reason,
                $after->current_version_id !== $before => $changed[] = $source->title ?: $source->host,
                default => $unchanged[] = $source->title ?: $source->host,
            };
        }

        $summary = sprintf('Re-checked %d page(s): %d changed, %d unchanged, %d could not be read.', $sources->count(), count($changed), count($unchanged), count($failed));

        if ($changed !== []) {
            $context->event('artifact', 'Changed pages (requirements are re-read from the new versions): '.implode(', ', $changed).'.');
        }

        return TaskOutcome::completed($summary, ['checked' => $sources->count(), 'changed' => $changed, 'unchanged' => $unchanged, 'failed' => $failed]);
    }
}
