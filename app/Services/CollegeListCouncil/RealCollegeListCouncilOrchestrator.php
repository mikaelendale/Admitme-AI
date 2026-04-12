<?php

namespace App\Services\CollegeListCouncil;

use App\Ai\Agents\ContextualAuditorAgent;
use App\Ai\Agents\CouncilJudgeAgent;
use App\Ai\Agents\GlobalScoutAgent;
use App\Ai\Tools\GetStudentAdmissionContextTool;
use App\Jobs\CollegeListCouncilAutoContinuePreflightJob;
use App\Models\CollegeListRun;
use App\Models\CollegeListRunEvent;
use App\Models\User;
use App\Services\CollegeList\CollegeListAssemblyService;
use Illuminate\Support\Arr;
use Throwable;

class RealCollegeListCouncilOrchestrator implements CollegeListCouncilOrchestratorContract
{
    public function __construct(
        protected CollegeListRunRecorder $recorder,
        protected CollegeListAssemblyService $assembly,
    ) {}

    public function execute(CollegeListRun $run, User $user): void
    {
        $contextTool = new GetStudentAdmissionContextTool($user);
        $studentContext = $contextTool->buildPayload($user, 'full');
        $evaluator = app(CollegeListCouncilPreflightEvaluator::class);

        $run = $run->fresh();
        $meta = is_array($run->meta) ? $run->meta : [];

        if (($meta['preflight_resumed'] ?? false) === true
            && is_array($run->preflight_payload)
            && $run->preflight_payload !== []) {
            $this->runScoutJudgeAssembly($run, $user, $studentContext, $run->preflight_payload);

            return;
        }

        $this->recorder->transitionStatus($run, CollegeListRun::STATUS_PREFLIGHT);

        $run = $run->fresh();
        $phaseStart = hrtime(true);

        $auditor = ContextualAuditorAgent::make()->prompt(json_encode([
            'student_context' => $studentContext,
            'student_request' => $run->request_message,
        ], JSON_UNESCAPED_UNICODE));

        $preflight = StructuredAgentResponseMapper::toArray($auditor);
        $run->forceFill(['preflight_payload' => $preflight])->save();

        $this->appendPreflightInsightEvents($run, $preflight);

        CollegeListCouncilRunLog::info('Council preflight complete', [
            'run_id' => (string) $run->id,
            'user_id' => $run->user_id,
            'duration_ms' => $this->elapsedMs($phaseStart),
            'strategic_constraints_count' => count(Arr::wrap($preflight['strategic_constraints'] ?? [])),
            'insights_streamed' => count(array_slice(Arr::wrap($preflight['auditor_insights'] ?? []), 0, 3)),
        ]);

        $gaps = $evaluator->normalizedInfoGaps($preflight);
        if ($gaps !== []
            && (bool) config('college_list_council.pause_for_preflight_in_job', true)) {
            $timeout = max(30, (int) config('college_list_council.preflight_auto_continue_seconds', 120));
            $this->recorder->pauseForPreflightAnswers($run, $gaps, $timeout);
            CollegeListCouncilAutoContinuePreflightJob::dispatch((string) $run->id)
                ->delay(now()->addSeconds($timeout));

            return;
        }

        $this->runScoutJudgeAssembly($run->fresh(), $user, $studentContext, $preflight);
    }

    /**
     * @param  array<string, mixed>  $preflight
     */
    private function runScoutJudgeAssembly(CollegeListRun $run, User $user, array $studentContext, array $preflight): void
    {
        $run = $run->fresh();
        $effectiveRequest = $this->effectiveStudentRequest($run);

        $this->recorder->transitionStatus($run, CollegeListRun::STATUS_SCOUTING);

        $phaseStart = hrtime(true);
        $scoutPrompt = json_encode([
            'student_context' => $studentContext,
            'strategic_constraints' => Arr::wrap($preflight['strategic_constraints'] ?? []),
            'student_request' => $effectiveRequest,
        ], JSON_UNESCAPED_UNICODE);

        $scoutResponse = GlobalScoutAgent::make()->prompt($scoutPrompt);
        $scout = StructuredAgentResponseMapper::toArray($scoutResponse);
        $run->forceFill(['scout_payload' => $scout])->save();

        $candidates = Arr::wrap($scout['candidates'] ?? []);
        $maxScoutFeed = (int) config('college_list_council.max_scout_insight_events', 8);
        $scoutShown = 0;
        foreach ($candidates as $c) {
            if (! is_array($c) || $scoutShown >= $maxScoutFeed) {
                break;
            }
            $name = (string) ($c['school_name'] ?? '');
            $note = (string) ($c['scout_note'] ?? '');
            if ($name === '' || $note === '') {
                continue;
            }
            $this->recorder->appendEvent(
                $run,
                'scout',
                CollegeListRunEvent::SEVERITY_MATCH,
                'Scout: '.$name,
                $note,
                isset($c['school_slug']) ? (string) $c['school_slug'] : null,
            );
            $scoutShown++;
        }

        CollegeListCouncilRunLog::info('Council scouting complete', [
            'run_id' => (string) $run->id,
            'user_id' => $run->user_id,
            'duration_ms' => $this->elapsedMs($phaseStart),
            'candidates_count' => count($candidates),
            'scout_events_streamed' => $scoutShown,
        ]);

        $this->recorder->transitionStatus($run, CollegeListRun::STATUS_JUDGING);

        $batchSize = max(3, min(14, (int) config('college_list_council.judge_batch_size', 10)));
        $allVerdicts = [];
        $phaseStart = hrtime(true);
        $batchIndex = 0;

        foreach (array_chunk($candidates, $batchSize) as $batch) {
            if ($batch === []) {
                continue;
            }

            $judgePrompt = json_encode([
                'student_context' => $studentContext,
                'strategic_constraints' => Arr::wrap($preflight['strategic_constraints'] ?? []),
                'candidates' => $batch,
            ], JSON_UNESCAPED_UNICODE);

            $batchStart = hrtime(true);
            try {
                $judgeResponse = CouncilJudgeAgent::make()->prompt($judgePrompt);
                $judgeChunk = StructuredAgentResponseMapper::toArray($judgeResponse);
                $verdicts = Arr::wrap($judgeChunk['verdicts'] ?? []);
                foreach ($verdicts as $v) {
                    if (is_array($v)) {
                        $allVerdicts[] = $v;
                    }
                }

                CollegeListCouncilRunLog::info('Council judge batch complete', [
                    'run_id' => (string) $run->id,
                    'user_id' => $run->user_id,
                    'batch_index' => $batchIndex,
                    'batch_candidates' => count($batch),
                    'verdicts_in_batch' => count($verdicts),
                    'duration_ms' => $this->elapsedMs($batchStart),
                ]);
                $batchIndex++;
            } catch (Throwable $e) {
                CollegeListCouncilRunLog::warning('Council judge batch failed', [
                    'run_id' => (string) $run->id,
                    'user_id' => $run->user_id,
                    'batch_index' => $batchIndex,
                    'error' => $e->getMessage(),
                ]);
                $batchIndex++;
            }
        }

        $verdictCounts = $this->verdictTally($allVerdicts);
        CollegeListCouncilRunLog::info('Council judging complete', [
            'run_id' => (string) $run->id,
            'user_id' => $run->user_id,
            'duration_ms' => $this->elapsedMs($phaseStart),
            'batches_attempted' => $batchIndex,
            'verdicts_total' => count($allVerdicts),
            'verdicts_by_type' => $verdictCounts,
        ]);

        $run->forceFill(['judge_payload' => ['verdicts' => $allVerdicts]])->save();

        $this->broadcastJudgeFeed($run, $allVerdicts);

        $this->recorder->transitionStatus($run, CollegeListRun::STATUS_ASSEMBLING);

        $finalSchools = $this->selectSchoolsForAssembly($allVerdicts);
        if ($finalSchools === [] && $candidates !== []) {
            throw new \RuntimeException('Council produced no schools to save after judging.');
        }

        $intent = $run->intent;
        $action = in_array($intent, ['generate'], true) ? 'generate' : 'refine';

        $payload = [
            'action' => $action,
            'schools' => $finalSchools,
            'removed_slugs' => [],
            'chat_response' => '',
        ];

        $phaseStart = hrtime(true);
        $this->assembly->persistFromAgentPayload(
            $user,
            $intent,
            $payload,
            (string) $run->id,
        );

        CollegeListCouncilRunLog::info('Council assembly persisted', [
            'run_id' => (string) $run->id,
            'user_id' => $run->user_id,
            'duration_ms' => $this->elapsedMs($phaseStart),
            'intent' => $intent,
            'schools_saved' => count($finalSchools),
        ]);

        $summary = $this->buildDeterministicSummary($preflight, $allVerdicts, count($finalSchools));

        if (config('college_list_council.llm_summary_enabled')) {
            // Optional: extra LLM call could append narrative; skipped by default.
        }

        $this->recorder->complete($run->fresh(), $summary);

        CollegeListCouncilRunLog::info('Council run marked complete', [
            'run_id' => (string) $run->id,
            'user_id' => $run->user_id,
            'status' => CollegeListRun::STATUS_COMPLETED,
            'summary_excerpt' => mb_substr($summary, 0, 280),
        ]);
    }

    private function effectiveStudentRequest(CollegeListRun $run): string
    {
        $run = $run->fresh();
        $base = (string) $run->request_message;
        $meta = is_array($run->meta) ? $run->meta : [];
        $reply = trim((string) ($meta['preflight_student_reply'] ?? ''));
        if ($reply !== '') {
            return $base."\n\n---\nStudent clarifications (from council card):\n".$reply;
        }
        if (($meta['preflight_auto_continued'] ?? false) === true) {
            return $base."\n\n---\n(Continuing without extra student input after the wait period.)";
        }

        return $base;
    }

    /**
     * @param  list<array<string, mixed>>  $verdicts
     * @return array<string, int>
     */
    protected function verdictTally(array $verdicts): array
    {
        $tally = ['advance' => 0, 'debate' => 0, 'discard' => 0, 'other' => 0];
        foreach ($verdicts as $v) {
            if (! is_array($v)) {
                continue;
            }
            $vType = (string) ($v['verdict'] ?? '');
            if (isset($tally[$vType])) {
                $tally[$vType]++;
            } else {
                $tally['other']++;
            }
        }

        return $tally;
    }

    /**
     * @param  array<string, mixed>  $preflight
     */
    private function appendPreflightInsightEvents(CollegeListRun $run, array $preflight): void
    {
        $insights = array_slice(Arr::wrap($preflight['auditor_insights'] ?? []), 0, 3);
        foreach ($insights as $line) {
            if (is_string($line) && $line !== '') {
                $this->recorder->appendEvent(
                    $run,
                    'preflight',
                    CollegeListRunEvent::SEVERITY_INFO,
                    'Contextual Auditor',
                    $line,
                );
            }
        }

        $infoGaps = array_slice(Arr::wrap($preflight['info_gaps'] ?? []), 0, 5);
        foreach ($infoGaps as $gap) {
            if (is_string($gap) && $gap !== '') {
                $this->recorder->appendEvent(
                    $run,
                    'preflight',
                    CollegeListRunEvent::SEVERITY_INFO,
                    'More detail would help',
                    $gap,
                );
            }
        }
    }

    private function elapsedMs(int|float $startHrTime): int
    {
        return (int) ((hrtime(true) - $startHrTime) / 1_000_000);
    }

    /**
     * @param  list<array<string, mixed>>  $verdicts
     */
    protected function broadcastJudgeFeed(CollegeListRun $run, array $verdicts): void
    {
        $shown = 0;
        foreach ($verdicts as $v) {
            if (! is_array($v) || $shown >= 24) {
                break;
            }
            $verdict = (string) ($v['verdict'] ?? '');
            $name = (string) ($v['school_name'] ?? '');
            $slug = (string) ($v['school_slug'] ?? '');
            $blurb = (string) ($v['honest_blurb'] ?? '');
            if ($name === '') {
                continue;
            }

            if ($verdict === 'discard') {
                $this->recorder->appendEvent(
                    $run,
                    'judge',
                    CollegeListRunEvent::SEVERITY_DISCARDED,
                    'Discarded: '.$name,
                    $blurb !== '' ? $blurb : null,
                    $slug !== '' ? $slug : null,
                );
                $shown++;
            } elseif ($verdict === 'debate') {
                $this->recorder->appendEvent(
                    $run,
                    'judge',
                    CollegeListRunEvent::SEVERITY_DEBATE,
                    'Judge debating: '.$name,
                    $blurb !== '' ? $blurb : null,
                    $slug !== '' ? $slug : null,
                );
                $shown++;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $verdicts
     * @return list<array<string, mixed>>
     */
    protected function selectSchoolsForAssembly(array $verdicts): array
    {
        $max = max(4, min(16, (int) config('college_list_council.final_list_max', 12)));
        $min = max(3, min($max, (int) config('college_list_council.final_list_min', 8)));

        $advance = array_values(array_filter($verdicts, fn ($v) => is_array($v) && ($v['verdict'] ?? '') === 'advance'));
        usort($advance, fn ($a, $b) => ((int) ($b['fit_score'] ?? 0)) <=> ((int) ($a['fit_score'] ?? 0)));

        $picked = [];
        foreach ($advance as $v) {
            $picked[] = $this->verdictToSchoolRow($v);
            if (count($picked) >= $max) {
                break;
            }
        }

        if (count($picked) < $min) {
            $debate = array_values(array_filter($verdicts, fn ($v) => is_array($v) && ($v['verdict'] ?? '') === 'debate'));
            usort($debate, fn ($a, $b) => ((int) ($b['fit_score'] ?? 0)) <=> ((int) ($a['fit_score'] ?? 0)));
            foreach ($debate as $v) {
                if (count($picked) >= $max) {
                    break;
                }
                $picked[] = $this->verdictToSchoolRow($v);
            }
        }

        return $picked;
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    protected function verdictToSchoolRow(array $v): array
    {
        $why = trim((string) ($v['why_blurb'] ?? ''));
        $honest = trim((string) ($v['honest_blurb'] ?? ''));
        $merged = $why;
        if ($honest !== '') {
            $merged .= ($merged !== '' ? "\n\n" : '').$honest;
        }

        return [
            'school_name' => (string) ($v['school_name'] ?? ''),
            'school_slug' => (string) ($v['school_slug'] ?? ''),
            'tier' => (string) ($v['tier'] ?? 'match'),
            'fit_score' => (int) ($v['fit_score'] ?? 70),
            'why_blurb' => $merged !== '' ? $merged : 'This school was selected as a strong fit based on your academic profile and goals. Visit the school detail page to learn more.',
            'acceptance_rate' => $v['acceptance_rate'] ?? null,
            'median_gpa' => $v['median_gpa'] ?? null,
            'median_sat' => $v['median_sat'] ?? null,
            'median_act' => $v['median_act'] ?? null,
            'ea_deadline' => $v['ea_deadline'] ?? null,
            'ed_deadline' => $v['ed_deadline'] ?? null,
            'rd_deadline' => $v['rd_deadline'] ?? null,
            'application_portal_url' => $v['application_portal_url'] ?? null,
            'admissions_cycle_label' => $v['admissions_cycle_label'] ?? null,
            'aid_estimate_band' => $v['aid_estimate_band'] ?? null,
            'notable_programs' => $v['notable_programs'] ?? [],
            'campus_size' => $v['campus_size'] ?? null,
            'location_city' => $v['location_city'] ?? null,
            'location_state' => $v['location_state'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  list<array<string, mixed>>  $verdicts
     */
    protected function buildDeterministicSummary(array $preflight, array $verdicts, int $finalCount): string
    {
        $adv = count(array_filter($verdicts, fn ($v) => is_array($v) && ($v['verdict'] ?? '') === 'advance'));
        $deb = count(array_filter($verdicts, fn ($v) => is_array($v) && ($v['verdict'] ?? '') === 'debate'));
        $dis = count(array_filter($verdicts, fn ($v) => is_array($v) && ($v['verdict'] ?? '') === 'discard'));

        $constraints = array_slice(Arr::wrap($preflight['strategic_constraints'] ?? []), 0, 2);
        $constraintLine = $constraints !== [] ? ' Key constraints: '.implode(' ', array_map(strval(...), $constraints)) : '';

        return 'Council run finished. We evaluated '.count($verdicts)." candidates ({$adv} advanced, {$deb} debated, {$dis} discarded) and saved {$finalCount} schools to your list.{$constraintLine}";
    }
}
