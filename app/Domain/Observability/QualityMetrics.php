<?php

namespace App\Domain\Observability;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Shared\ChangeSource;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\AiContextSnapshot;
use App\Models\Application;
use App\Models\ApplicationItem;
use App\Models\BrowserCommand;
use App\Models\Proposal;
use App\Models\ResearchAnswer;
use App\Models\StudentFact;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Measures from stored records (plan Phase 11): source-supported answers, extraction corrections, wrong-field
 * fills, completed tasks, manual intervention, latency and usage per workflow — plus integrity checks for
 * the "false success" states the app is designed never to reach (each should stay 0).
 */
final class QualityMetrics
{
    /**
     * @return array<string, array<string, int|float|string|null>>
     */
    public function report(CarbonInterface $since): array
    {
        return [
            'mentor' => $this->mentor($since),
            'specialists' => $this->specialists($since),
            'research' => $this->research($since),
            'document_extraction' => $this->documents($since),
            'approvals' => $this->approvals($since),
            'tasks' => $this->tasks($since),
            'browser' => $this->browser($since),
            'integrity' => $this->integrity(),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function mentor(CarbonInterface $since): array
    {
        $runs = AiContextSnapshot::query()->where('created_at', '>=', $since)->get(['outcome', 'duration_ms', 'usage', 'tool_calls']);
        $outcomes = $runs->flatMap(fn (AiContextSnapshot $r) => array_column((array) $r->tool_calls, 'outcome'))->countBy();

        return [
            'turns' => $runs->count(),
            'failed' => $runs->where('outcome', 'failed')->count(),
            'failure_rate' => $this->rate($runs->where('outcome', 'failed')->count(), $runs->count()),
            'p50_ms' => $this->percentile($runs->pluck('duration_ms'), 50),
            'p90_ms' => $this->percentile($runs->pluck('duration_ms'), 90),
            'input_tokens' => (int) $runs->sum(fn (AiContextSnapshot $r) => (int) ($r->usage['input_tokens'] ?? 0)),
            'output_tokens' => (int) $runs->sum(fn (AiContextSnapshot $r) => (int) ($r->usage['output_tokens'] ?? 0)),
            'estimated_cost_usd' => $this->cost($runs->map(fn (AiContextSnapshot $r) => [$r->model, $r->usage])),
            'tool_calls' => (int) $outcomes->sum(),
            'tool_calls_awaiting_approval' => (int) ($outcomes['pending_approval'] ?? 0),
            'tool_calls_refused_or_failed' => (int) (($outcomes['not_proposed'] ?? 0) + ($outcomes['failed'] ?? 0) + ($outcomes['denied'] ?? 0) + ($outcomes['invalid'] ?? 0)),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function specialists(CarbonInterface $since): array
    {
        $runs = AgentRun::query()->where('created_at', '>=', $since)->get(['task_id', 'status', 'duration_ms', 'started_at', 'finished_at', 'usage', 'model', 'instruction_version']);
        $durations = $runs->map(fn (AgentRun $r) => $r->duration_ms ?? ($r->started_at && $r->finished_at ? (int) $r->started_at->diffInMilliseconds($r->finished_at) : null));

        return [
            'runs' => $runs->count(),
            'failed' => $runs->where('status', 'failed')->count(),
            'failure_rate' => $this->rate($runs->where('status', 'failed')->count(), $runs->count()),
            'p50_ms' => $this->percentile($durations, 50),
            'p90_ms' => $this->percentile($durations, 90),
            'input_tokens' => (int) $runs->sum(fn (AgentRun $r) => (int) ($r->usage['input_tokens'] ?? 0)),
            'output_tokens' => (int) $runs->sum(fn (AgentRun $r) => (int) ($r->usage['output_tokens'] ?? 0)),
            'estimated_cost_usd' => $this->cost($runs->map(fn (AgentRun $r) => [$r->model, $r->usage])),
            'models' => $runs->pluck('model')->filter()->unique()->count(),
            'code_versions' => $runs->pluck('instruction_version')->filter()->unique()->count(),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function research(CarbonInterface $since): array
    {
        $claims = ResearchAnswer::query()->where('created_at', '>=', $since)->get(['claims'])->flatMap(fn (ResearchAnswer $a) => (array) $a->claims);
        $statuses = $claims->countBy(fn ($claim) => is_array($claim) ? ($claim['status'] ?? 'unknown') : 'unknown');

        return [
            'answers' => ResearchAnswer::query()->where('created_at', '>=', $since)->count(),
            'claims' => $claims->count(),
            'supported' => (int) ($statuses['supported'] ?? 0),
            'unresolved' => (int) ($statuses['unresolved'] ?? 0),
            'conflicting' => (int) ($statuses['conflicting'] ?? 0),
            'source_supported_rate' => $this->rate((int) ($statuses['supported'] ?? 0), $claims->count()),
        ];
    }

    /**
     * Values read from documents and what the student did with them: accepted as read, corrected, rejected.
     *
     * @return array<string, int|float|null>
     */
    private function documents(CarbonInterface $since): array
    {
        $candidates = StudentFact::query()->where('source_type', ChangeSource::SOURCE_DOCUMENT)->where('actor_type', ChangeSource::ACTOR_SYSTEM)->where('created_at', '>=', $since)->get(['review_state']);
        $confirmations = StudentFact::query()->where('source_type', ChangeSource::SOURCE_DOCUMENT)->where('actor_type', ChangeSource::ACTOR_STUDENT)->where('created_at', '>=', $since)->get(['source_ref']);
        $edited = $confirmations->filter(fn (StudentFact $f) => ($f->source_ref['edited'] ?? false) === true && isset($f->source_ref['candidate_id']))->count();
        $accepted = $candidates->where('review_state', StudentFact::REVIEW_ACCEPTED)->count();
        $rejected = $candidates->where('review_state', StudentFact::REVIEW_REJECTED)->count();

        return [
            'values_read' => $candidates->count(),
            'awaiting_review' => $candidates->where('review_state', StudentFact::REVIEW_PENDING)->count(),
            'accepted_as_read' => max(0, $accepted - $edited),
            'corrected_by_student' => $edited,
            'rejected' => $rejected,
            'correction_rate' => $this->rate($edited + $rejected, $accepted + $rejected),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function approvals(CarbonInterface $since): array
    {
        $proposals = Proposal::query()->with('decision')->where('created_at', '>=', $since)->get();
        $decided = $proposals->filter(fn (Proposal $p) => $p->decision !== null);
        $partial = $decided->filter(fn (Proposal $p) => $p->decision->decision === 'approve' && count((array) $p->decision->selected_keys) < count($p->items))->count();
        $editedItems = $proposals->sum(fn (Proposal $p) => count(array_filter($p->items, fn (array $item) => (bool) ($item['edited_by_student'] ?? false))));

        return [
            'proposals' => $proposals->count(),
            'applied' => $proposals->where('status', ProposalStatus::Applied)->count(),
            'partially_approved' => $partial,
            'rejected' => $proposals->where('status', ProposalStatus::Rejected)->count(),
            'stale' => $proposals->where('status', ProposalStatus::Stale)->count(),
            'expired' => $proposals->where('status', ProposalStatus::Expired)->count(),
            'pending' => $proposals->where('status', ProposalStatus::Pending)->count(),
            'items_edited_by_student' => (int) $editedItems,
            'approval_rate' => $this->rate($proposals->where('status', ProposalStatus::Applied)->count(), $decided->count()),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function tasks(CarbonInterface $since): array
    {
        $status = fn (AgentTask $task): string => $task->status instanceof BackedEnum ? (string) $task->status->value : (string) $task->status;
        $goals = AgentTask::query()->whereNull('parent_id')->where('created_at', '>=', $since)->get(['id', 'status']);
        $steps = AgentTask::query()->whereNotNull('parent_id')->where('created_at', '>=', $since)->get(['id', 'parent_id', 'status', 'attempts', 'proposal_id', 'usage', 'started_at', 'finished_at']);
        $goalStatuses = $goals->map($status)->countBy();
        $completedGoals = $goals->filter(fn (AgentTask $g) => $status($g) === 'completed')->pluck('id')->all();
        $completedStepUsage = $steps->whereIn('parent_id', $completedGoals);
        $tokens = (int) $completedStepUsage->sum(fn (AgentTask $s) => (int) ($s->usage['input_tokens'] ?? 0) + (int) ($s->usage['output_tokens'] ?? 0));
        $durations = $steps->map(fn (AgentTask $s) => $s->started_at && $s->finished_at ? (int) $s->started_at->diffInMilliseconds($s->finished_at) : null);
        $finished = (int) (($goalStatuses['completed'] ?? 0) + ($goalStatuses['failed'] ?? 0) + ($goalStatuses['cancelled'] ?? 0));

        return [
            'goals' => $goals->count(),
            'completed' => count($completedGoals),
            'failed' => (int) ($goalStatuses['failed'] ?? 0),
            'cancelled' => (int) ($goalStatuses['cancelled'] ?? 0),
            'completion_rate' => $this->rate(count($completedGoals), $finished),
            'steps' => $steps->count(),
            'steps_retried' => $steps->filter(fn (AgentTask $s) => $s->attempts > 1)->count(),
            'steps_needing_the_student' => $steps->filter(fn (AgentTask $s) => $s->proposal_id !== null || $status($s) === 'blocked')->count(),
            'step_p50_ms' => $this->percentile($durations, 50),
            'step_p90_ms' => $this->percentile($durations, 90),
            'tokens_per_completed_goal' => count($completedGoals) > 0 ? (int) round($tokens / count($completedGoals)) : null,
            'cost_per_completed_goal_usd' => count($completedGoals) > 0 && ($total = $this->cost(AgentRun::query()->whereIn('task_id', $completedStepUsage->pluck('id'))->get(['model', 'usage'])->map(fn (AgentRun $r) => [$r->model, $r->usage]))) !== null
                ? round($total / count($completedGoals), 4)
                : null,
        ];
    }

    /**
     * Estimated cost from recorded tokens, only when the operator has entered prices for every model involved
     * (config admitme.pricing: model => [input_per_million, output_per_million] in USD). Never guessed.
     *
     * @param  Collection<int, array{0: string|null, 1: array<string, int>|null}>  $runs
     */
    private function cost(Collection $runs): ?float
    {
        $prices = (array) config('admitme.pricing', []);
        $total = 0.0;

        foreach ($runs as [$model, $usage]) {
            if ($usage === null || $usage === []) {
                continue;
            }

            $price = $prices[$model] ?? null;

            if (! is_array($price) || ! isset($price['input_per_million'], $price['output_per_million'])) {
                return null;
            }

            $total += (int) ($usage['input_tokens'] ?? 0) / 1_000_000 * (float) $price['input_per_million']
                + (int) ($usage['output_tokens'] ?? 0) / 1_000_000 * (float) $price['output_per_million'];
        }

        return round($total, 4);
    }

    /**
     * @return array<string, int|float|null>
     */
    private function browser(CarbonInterface $since): array
    {
        $all = BrowserCommand::query()->where('created_at', '>=', $since)->get(['type', 'status']);
        // Approved fills (typed and read back) and the mentor's own actions in a tab the student gave it.
        [$agent, $commands] = $all->partition(fn (BrowserCommand $c) => $c->isAgent());
        $completed = $commands->where('status', 'completed')->count();
        $frozen = $commands->where('status', 'frozen')->count();

        return [
            'steps' => $commands->count(),
            'typed_and_verified' => $completed,
            'wrong_or_unverified' => $frozen,
            'wrong_fill_rate' => $this->rate($frozen, $completed + $frozen),
            'stale' => $commands->where('status', 'stale')->count(),
            'failed' => $commands->where('status', 'failed')->count(),
            'mentor_actions' => $agent->count(),
            'mentor_actions_failed' => $agent->where('status', 'failed')->count(),
            'mentor_actions_declined' => $agent->where('status', 'declined')->count(),
        ];
    }

    /**
     * States that would mean AdmitMe reported success it cannot know (all time; each should be 0).
     *
     * @return array<string, int>
     */
    private function integrity(): array
    {
        return [
            'submitted_without_evidence' => Application::query()->where('status', ApplicationStatus::Submitted->value)->whereNull('submission_evidence_type')->count(),
            'third_party_received_without_basis' => ApplicationItem::query()->where('owner_type', 'third_party')->where('status', 'received')->whereNull('completion_basis')->count(),
            'browser_completed_without_matching_read_back' => BrowserCommand::query()->where('status', 'completed')->whereNotIn('type', BrowserCommand::AGENT_TYPES)->get(['payload', 'result'])->filter(fn (BrowserCommand $c) => ($c->result['observed_value'] ?? null) !== (string) ($c->payload['value'] ?? ''))->count(),
            'proposals_applied_without_decision' => Proposal::query()->where('status', ProposalStatus::Applied)->doesntHave('decision')->count(),
        ];
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 3) : null;
    }

    /**
     * @param  Collection<int, int|null>  $values
     */
    private function percentile(Collection $values, int $percentile): ?int
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->sort()->values();

        if ($sorted->isEmpty()) {
            return null;
        }

        return $sorted[(int) min($sorted->count() - 1, max(0, (int) ceil($percentile / 100 * $sorted->count()) - 1))];
    }
}
