<?php

namespace App\Ai\Tools;

use App\Models\CollegeListSchool;
use App\Models\GapFixAnalysis;
use App\Models\User;
use App\Notifications\GapFixReadyNotification;
use App\Services\GapFix\GapFixAnalysisService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Triggers a gap fix analysis for selected schools directly from chat.
 */
class StartGapFixTool implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Run a **gap analysis** (rejection risk + fix roadmap) for up to 6 schools on the student's saved college list. Returns a completed analysis immediately — no background job.

**Hard requirements — do not skip:**
1. Call **GetStudentCollegeList** first to get real school IDs.
2. Confirm which schools the student wants analyzed. If they say "my reaches" or "all", resolve real IDs from the list. If unclear, ask.
3. Max **6 schools** per run. If more, tell them to pick 6.
4. Copy `id` values **verbatim** from GetStudentCollegeList. Never guess.

On success, the gap fix card appears below your message automatically.

Parameters: `school_ids` (array of ULIDs), `message` (context).
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $schoolIdsRaw = $request->input('school_ids');
            if (! is_array($schoolIdsRaw) || $schoolIdsRaw === []) {
                return json_encode([
                    'ok' => false,
                    'error' => 'school_ids is required and must be a non-empty array.',
                    'retryable' => false,
                    'suggestion' => 'Call GetStudentCollegeList and pass real school IDs.',
                ], JSON_UNESCAPED_UNICODE);
            }

            $schoolIds = array_values(array_filter(array_map('strval', $schoolIdsRaw)));
            if ($schoolIds === []) {
                return json_encode([
                    'ok' => false,
                    'error' => 'school_ids must contain at least one valid ID.',
                    'retryable' => false,
                    'suggestion' => 'Use IDs from GetStudentCollegeList.',
                ], JSON_UNESCAPED_UNICODE);
            }

            if (count($schoolIds) > 6) {
                return json_encode([
                    'ok' => false,
                    'error' => 'Maximum 6 schools per gap analysis. Please select fewer schools.',
                    'retryable' => false,
                    'suggestion' => 'Ask the student to pick up to 6 schools.',
                ], JSON_UNESCAPED_UNICODE);
            }

            $schools = CollegeListSchool::query()
                ->where('user_id', $this->user->id)
                ->where('is_removed', false)
                ->whereIn('id', $schoolIds)
                ->get();

            $foundIds = $schools->pluck('id')->map(fn ($id) => (string) $id)->all();
            $invalidIds = array_diff($schoolIds, $foundIds);

            if ($invalidIds !== []) {
                return json_encode([
                    'ok' => false,
                    'error' => 'These school IDs are not on the student\'s active list: '.implode(', ', $invalidIds).'. Call GetStudentCollegeList and use IDs from there.',
                    'retryable' => false,
                    'suggestion' => 'Refresh the list with GetStudentCollegeList and copy IDs exactly.',
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }

            $service = app(GapFixAnalysisService::class);
            $out = $this->runGapFixWithRetry(fn () => $service->generate($this->user, $schoolIds));

            $analysis = $out['analysis'];
            $schoolNames = $schools->pluck('school_name')->all();

            try {
                $this->user->notify(new GapFixReadyNotification(count($schoolIds)));
            } catch (\Throwable) {
                // Notification failure must not affect tool output.
            }

            return json_encode([
                'ok' => true,
                'gap_fix_started' => true,
                'analysis_id' => (string) $analysis->id,
                'school_count' => count($schoolIds),
                'school_names' => $schoolNames,
                'chat_response' => 'Gap analysis complete for '.count($schoolIds).' school'.(count($schoolIds) > 1 ? 's' : '').'. Your rejection risks and fix roadmap are ready.',
                'action_url' => '/gap-fix',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => 'Gap analysis failed: '.$e->getMessage(),
                'retryable' => true,
                'suggestion' => 'Try again in a moment, or run on fewer schools if the request was large.',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    /**
     * One automatic retry after 2s (transient AI / network failures).
     *
     * @param  callable(): array{analysis: GapFixAnalysis}  $runner
     * @return array{analysis: GapFixAnalysis}
     */
    private function runGapFixWithRetry(callable $runner): array
    {
        try {
            return $runner();
        } catch (\Throwable) {
            usleep(2_000_000);

            return $runner();
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'school_ids' => $schema->array()
                ->items($schema->string()->max(36))
                ->description('ULIDs of college_list_schools to analyze — must belong to this user. Get them from GetStudentCollegeList first.')
                ->required(),
            'message' => $schema->string()
                ->max(2000)
                ->description('Short context message about what the student wants to understand (e.g. "focus on reach schools").')
                ->required(),
        ];
    }
}
