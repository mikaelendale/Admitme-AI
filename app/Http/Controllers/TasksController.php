<?php

namespace App\Http\Controllers;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Tasks\Orchestrator;
use App\Domain\Tasks\TaskPresenter;
use App\Models\CollegeListSchool;
use App\Models\JobTracking;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The student's background work. Until the Phase 7 orchestrator exists, these are the genuine
 * job records the app already keeps (see docs/admitme/decisions.md D-004).
 */
class TasksController extends Controller
{
    public function __invoke(Request $request, Orchestrator $orchestrator): Response
    {
        // Opening the panel also picks up steps a stopped worker left behind...
        $orchestrator->recover($request->user());

        $goals = $orchestrator->goalsFor($request->user(), 10)->map(fn ($goal) => TaskPresenter::goal($goal))->values()->all();

        $jobs = JobTracking::query()
            ->where('user_id', $request->user()->getKey())
            ->latest('id')
            ->limit(50)
            ->get();

        // A research run's suggestions are its artifacts: show how many still wait for the student's decision.
        $runIds = $jobs->map(fn (JobTracking $job) => is_array($job->payload) ? ($job->payload['run_id'] ?? null) : null)->filter()->unique()->values();
        $awaiting = CollegeListSchool::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('college_list_run_id', $runIds)
            ->where('choice_status', CollegeChoiceStatus::Suggested)
            ->selectRaw('college_list_run_id, count(*) as aggregate')
            ->groupBy('college_list_run_id')
            ->pluck('aggregate', 'college_list_run_id');

        $tasks = $jobs
            ->map(function (JobTracking $job) use ($awaiting) {
                $runId = is_array($job->payload) ? ($job->payload['run_id'] ?? null) : null;
                $pending = $runId !== null ? (int) ($awaiting[$runId] ?? 0) : 0;

                return [
                    'id' => (int) $job->id,
                    'title' => $job->label(),
                    'type' => $job->job_type,
                    'status' => $job->status,
                    'startedAt' => $job->started_at?->toIso8601String(),
                    'completedAt' => $job->completed_at?->toIso8601String(),
                    'failedAt' => $job->failed_at?->toIso8601String(),
                    'error' => $job->status === JobTracking::STATUS_FAILED ? $job->error_message : null,
                    'href' => $this->linkFor($job),
                    'note' => $pending > 0 ? "{$pending} suggestion".($pending === 1 ? '' : 's').' awaiting your review' : null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('tasks', ['tasks' => $tasks, 'goals' => $goals]);
    }

    private function linkFor(JobTracking $job): ?string
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $running = in_array($job->status, [JobTracking::STATUS_PENDING, JobTracking::STATUS_RUNNING], true);

        return match ($job->job_type) {
            // A finished run's results are on the list itself (the run view is for following live progress)...
            JobTracking::TYPE_COLLEGE_LIST_COUNCIL, JobTracking::TYPE_COUNCIL_PREFLIGHT => $running && isset($payload['run_id']) ? '/college-lists?run='.urlencode((string) $payload['run_id']) : '/college-lists',
            JobTracking::TYPE_ESSAY_RUN => '/essay-suite',
            JobTracking::TYPE_AI_CONTEXT => '/profile-overview',
            JobTracking::TYPE_DOCUMENT_PROCESSING => isset($payload['document_id']) ? '/documents/'.urlencode((string) $payload['document_id']) : '/documents',
            default => null,
        };
    }
}
