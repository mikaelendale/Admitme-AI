<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Models\CollegeListRun;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Loads stored council outputs for follow-up questions ("why not MIT?") from DB — not a fresh guess.
 */
class GetCollegeListRunArtifactsTool implements ReadOnlyTool, Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Load artifacts from a completed (or in-progress) college list council run: status, summary, judge verdicts (if any), and timeline events. Use when the student asks why a school was excluded or what the council decided.

Parameters: `run_id` (ULID from StartCollegeListCouncilJobTool), optional `school_slug` to filter events/verdicts mentioning that slug.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        if (! config('college_list_council.enabled')) {
            return json_encode(['ok' => false, 'error' => 'Council feature disabled.'], JSON_UNESCAPED_UNICODE);
        }

        $runId = trim($request->string('run_id')->toString());
        if ($runId === '') {
            return json_encode(['ok' => false, 'error' => 'run_id is required.'], JSON_UNESCAPED_UNICODE);
        }

        $run = CollegeListRun::query()->find($runId);
        if ($run === null || (int) $run->user_id !== (int) $this->user->id) {
            return json_encode(['ok' => false, 'error' => 'Run not found.'], JSON_UNESCAPED_UNICODE);
        }

        $slugRaw = $request->input('school_slug');
        $slug = is_string($slugRaw) ? trim($slugRaw) : '';

        $judge = is_array($run->judge_payload) ? $run->judge_payload : [];
        $verdicts = $judge['verdicts'] ?? [];
        if ($slug !== '' && is_array($verdicts)) {
            $verdicts = array_values(array_filter(
                $verdicts,
                fn ($v) => is_array($v) && (($v['school_slug'] ?? '') === $slug || str_contains(strtolower((string) ($v['school_name'] ?? '')), strtolower($slug)))
            ));
        }

        $eventsQuery = $run->events()->orderBy('id');
        if ($slug !== '') {
            $eventsQuery->where(function ($q) use ($slug): void {
                $q->where('school_slug', $slug)
                    ->orWhere('title', 'like', '%'.$slug.'%');
            });
        }

        $events = $eventsQuery->limit(120)->get()->map(fn ($e) => [
            'phase' => $e->phase,
            'severity' => $e->severity,
            'title' => $e->title,
            'body' => $e->body,
            'school_slug' => $e->school_slug,
        ])->all();

        return json_encode([
            'ok' => true,
            'run_id' => (string) $run->id,
            'status' => $run->status,
            'summary_text' => $run->summary_text,
            'preflight_payload' => $run->preflight_payload,
            'scout_payload' => $run->scout_payload,
            'judge_verdicts_filtered' => $verdicts,
            'timeline_events_filtered' => $events,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->max(64)->description('ULID from StartCollegeListCouncilJobTool.')->required(),
            // OpenAI strict tools: every property must appear in `required`; use null when not filtering.
            'school_slug' => $schema->string()->max(160)->nullable()->description('Filter verdicts/events for this slug, or null for full run.')->required(),
        ];
    }
}
