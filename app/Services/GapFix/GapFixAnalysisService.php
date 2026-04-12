<?php

namespace App\Services\GapFix;

use App\Ai\Agents\GapFixAnalysisAgent;
use App\Models\CollegeListSchool;
use App\Models\GapFixAnalysis;
use App\Models\User;
use App\Services\CollegeListCouncil\StructuredAgentResponseMapper;
use App\Support\GapFix\GapFixPromptBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Promptable;

final class GapFixAnalysisService
{
    public function __construct(
        private readonly GapFixAnalysisPayloadValidator $validator,
    ) {}

    /**
     * @param  list<string>  $selectedSchoolIds
     * @return array{
     *   analysis: GapFixAnalysis,
     *   payload: array<string, mixed>,
     *   is_partial: bool,
     *   missing_school_ids: list<string>
     * }
     */
    public function generate(User $user, array $selectedSchoolIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $selectedSchoolIds))));
        if ($ids === []) {
            throw ValidationException::withMessages([
                'college_list_school_ids' => 'Select at least one school from your list.',
            ]);
        }

        if (count($ids) > 12) {
            throw ValidationException::withMessages([
                'college_list_school_ids' => 'Select at most 12 schools per run.',
            ]);
        }

        /** @var Collection<int, CollegeListSchool> $schools */
        $schools = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->where('is_removed', false)
            ->whereIn('id', $ids)
            ->get();

        if ($schools->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'college_list_school_ids' => 'One or more schools are invalid or not on your active list.',
            ]);
        }

        $ordered = [];
        foreach ($ids as $id) {
            $row = $schools->firstWhere('id', $id);
            if ($row !== null) {
                $ordered[] = $row;
            }
        }
        $schools = collect($ordered);

        $envelope = GapFixPromptBuilder::build($user, $schools);

        if (config('gap_fix.fake')) {
            $payload = $this->fakePayload($schools);
            $validated = $this->validator->validate($payload, $ids);
            if (! ($validated['ok'] ?? false)) {
                throw new \RuntimeException($validated['reason'] ?? 'Fake payload failed self-check.');
            }
            /** @var array{payload: array<string, mixed>, partial: bool, missing_school_ids: list<string>} $validated */

            return $this->persist($user, $ids, $validated['payload'], $validated['partial'], $validated['missing_school_ids']);
        }

        $prompt = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $payload = $this->callAgentWithRetry($prompt, $ids);

        return $this->persist($user, $ids, $payload['payload'], $payload['partial'], $payload['missing_school_ids']);
    }

    /**
     * @param  list<string>  $ids
     * @return array{payload: array<string, mixed>, partial: bool, missing_school_ids: list<string>}
     */
    private function callAgentWithRetry(string $prompt, array $ids): array
    {
        $first = $this->invokeAgent($prompt);
        $v1 = $this->validator->validate($first, $ids);
        if ($v1['ok'] ?? false) {
            /** @var array{ok: true, partial: bool, missing_school_ids: list<string>, payload: array<string, mixed>} $v1 */

            return [
                'payload' => $v1['payload'],
                'partial' => $v1['partial'],
                'missing_school_ids' => $v1['missing_school_ids'],
            ];
        }

        $reason = is_array($v1) && isset($v1['reason']) ? (string) $v1['reason'] : 'validation failed';

        $retryPrompt = json_encode([
            '_gap_fix_retry' => true,
            '_validation_error' => $reason,
            'gap_fix_request' => json_decode($prompt, true) ?: [],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        Log::warning('GapFixAnalysisService: first AI payload failed validation, retrying once.', [
            'reason' => $reason,
        ]);

        $second = $this->invokeAgent($retryPrompt);
        $v2 = $this->validator->validate($second, $ids);
        if (! ($v2['ok'] ?? false)) {
            $msg = is_array($v2) && isset($v2['reason']) ? (string) $v2['reason'] : 'Analysis could not be validated. Try fewer schools or Regenerate.';

            throw ValidationException::withMessages([
                'analysis' => $msg,
            ]);
        }

        /** @var array{ok: true, partial: bool, missing_school_ids: list<string>, payload: array<string, mixed>} $v2 */

        return [
            'payload' => $v2['payload'],
            'partial' => $v2['partial'],
            'missing_school_ids' => $v2['missing_school_ids'],
        ];
    }

    private function invokeAgent(string $prompt): array
    {
        /** @var Promptable&object $agent */
        $agent = GapFixAnalysisAgent::make();
        $response = $agent->prompt($prompt);

        return StructuredAgentResponseMapper::toArray($response);
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $missingSchoolIds
     * @return array{
     *   analysis: GapFixAnalysis,
     *   payload: array<string, mixed>,
     *   is_partial: bool,
     *   missing_school_ids: list<string>
     * }
     */
    private function persist(User $user, array $ids, array $payload, bool $isPartial, array $missingSchoolIds): array
    {
        $analysis = GapFixAnalysis::query()->create([
            'user_id' => $user->id,
            'selected_school_ids' => $ids,
            'payload' => $payload,
            'is_partial' => $isPartial,
            'missing_school_ids' => $missingSchoolIds === [] ? null : $missingSchoolIds,
        ]);

        return [
            'analysis' => $analysis,
            'payload' => $payload,
            'is_partial' => $isPartial,
            'missing_school_ids' => $missingSchoolIds,
        ];
    }

    /**
     * @param  Collection<int, CollegeListSchool>  $schools
     * @return array<string, mixed>
     */
    private function fakePayload(Collection $schools): array
    {
        $rejectionRisks = [];
        foreach ($schools as $s) {
            $rejectionRisks[] = [
                'college_list_school_id' => (string) $s->id,
                'reasons' => [
                    [
                        'text' => 'Academic profile vs reported medians at '.$s->school_name.' — align narrative with strongest data you have.',
                        'severity' => 'critical',
                    ],
                    [
                        'text' => 'List positioning: '.$s->tier.' — ensure essays and activities signal fit, not just prestige.',
                        'severity' => 'moderate',
                    ],
                    [
                        'text' => 'Timeline: verify testing and deadlines so materials match what this campus rewards.',
                        'severity' => 'minor',
                    ],
                ],
            ];
        }

        return [
            'rejection_risks' => $rejectionRisks,
            'gaps' => [
                [
                    'id' => 'testing_alignment',
                    'severity' => 'moderate',
                    'title' => 'Testing and median alignment',
                    'steps' => [
                        'Compare your SAT/ACT plan to each selected school’s reported medians.',
                        'If test-optional, decide school-by-school whether to submit scores.',
                        'Schedule one retest window only if calendar math still works for your year.',
                    ],
                ],
                [
                    'id' => 'story_depth',
                    'severity' => 'minor',
                    'title' => 'Activity depth vs breadth',
                    'steps' => [
                        'Pick one flagship activity and add one measurable outcome.',
                        'Trim long tail clubs that dilute the spike narrative.',
                        'Ask one recommender who saw the flagship work directly.',
                    ],
                ],
            ],
            'disclaimer' => 'Stub Gap + Fix output (GAP_FIX_FAKE=true). Not personalized model output.',
        ];
    }
}
