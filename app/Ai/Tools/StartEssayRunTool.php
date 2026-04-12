<?php

namespace App\Ai\Tools;

use App\Models\CollegeListSchool;
use App\Models\EssayRun;
use App\Models\User;
use App\Services\EssaySuite\EssayRunStarter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StartEssayRunTool implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Start a **background essay job** for **exactly one row** on the student's **saved, non-removed college list** (same pattern as the council: queued work, `run_id`, live card in chat).

**Hard requirements — do not skip:**
1. Call **GetStudentCollegeList** in the same turn or immediately before this tool.
2. If the list is **empty** or the tool says no rows: **do not call this tool.** Ask the student to build their list first, or name a school after it exists on the list.
3. If **multiple** schools are returned and the student did **not** clearly name one: **do not call this tool.** Reply with the school names (and their `id` values from the list tool) and **ask which college** they want this run for.
4. **`college_list_school_id`** must be copied **verbatim** from the `id` field of a row returned by GetStudentCollegeList for this user. Never invent, guess, or reuse stale ids from memory.

Modes: **brainstorm** (angles) | **draft** (expand seed in `message`).

Optional: `essay_prompt` (official supplement/Common App question — **required for draft**; for brainstorm, if omitted, `message` is treated as the prompt text), `essay_type` (common_app|supplemental|why_school; default supplemental), `word_limit`, `tone` (brutally_honest|balanced|supportive).

On success, tell them the job is running, to watch the **essay card**, and **split view** for full markdown. Essay suite: `/essay-suite`.

Parameters: `college_list_school_id`, `run_type`, `message`, optional `essay_prompt`, `essay_type`, `word_limit`, `tone`.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $schoolId = trim($request->string('college_list_school_id')->toString());
        if ($schoolId === '') {
            return json_encode(['ok' => false, 'error' => 'college_list_school_id is required.'], JSON_UNESCAPED_UNICODE);
        }

        $runType = $request->string('run_type')->toString();
        if (! in_array($runType, ['brainstorm', 'draft'], true)) {
            return json_encode(['ok' => false, 'error' => 'run_type must be brainstorm or draft.'], JSON_UNESCAPED_UNICODE);
        }

        $message = trim($request->string('message')->toString());
        if ($message === '') {
            return json_encode(['ok' => false, 'error' => 'message is required (brainstorm focus, or draft seed/outline).'], JSON_UNESCAPED_UNICODE);
        }

        $essayPromptRaw = trim($request->string('essay_prompt')->toString());
        if ($essayPromptRaw === '' && $runType === 'draft') {
            return json_encode([
                'ok' => false,
                'error' => 'essay_prompt is required for drafts: paste the exact application question. Put the student’s outline or paragraph in message.',
            ], JSON_UNESCAPED_UNICODE);
        }
        if ($essayPromptRaw === '') {
            $essayPromptRaw = $message;
        }

        $essayType = trim($request->string('essay_type')->toString());
        if (! in_array($essayType, EssayRun::essayTypes(), true)) {
            $essayType = EssayRun::ESSAY_TYPE_SUPPLEMENTAL;
        }

        $wordLimit = $request->filled('word_limit') ? max(1, (int) $request->integer('word_limit')) : null;
        $tone = $request->filled('tone') ? trim($request->string('tone')->toString()) : null;
        if ($tone !== null && $tone === '') {
            $tone = null;
        }

        $studentMessage = $runType === EssayRun::TYPE_DRAFT
            ? $message
            : ($essayPromptRaw === $message ? '' : $message);

        $onList = CollegeListSchool::query()
            ->where('user_id', $this->user->id)
            ->where('is_removed', false)
            ->where('id', $schoolId)
            ->exists();

        if (! $onList) {
            return json_encode([
                'ok' => false,
                'essay_job_started' => false,
                'error' => 'That college_list_school_id is not on this student’s active list (or the list is empty). Call GetStudentCollegeList; if there are zero rows, do not start an essay job—help them add schools first. If there are multiple rows, ask which school they want, then pass only that row’s id.',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $starter = app(EssayRunStarter::class);

        try {
            $run = $starter->start($this->user, $schoolId, $runType, $studentMessage, [
                'essay_type' => $essayType,
                'essay_prompt' => $essayPromptRaw,
                'word_limit' => $wordLimit,
                'tone' => $tone,
            ]);
        } catch (ConflictHttpException $e) {
            return json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return json_encode([
            'ok' => true,
            'essay_job_started' => true,
            'run_id' => (string) $run->id,
            'run_type' => $run->run_type,
            'status' => $run->status,
            'poll_url' => '/essay-suite/runs/'.(string) $run->id,
            'essay_suite_url' => '/essay-suite',
            'message' => 'Your essay job is running in the background. Watch the essay card in this chat for live status; open **split view** to read the full markdown when it finishes. You can also open the Essay suite for the full editor.',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $max = (int) config('essay_suite.max_prompt_length', 8000);

        return [
            'college_list_school_id' => $schema->string()
                ->max(36)
                ->description('Exact `id` from GetStudentCollegeList for one active list row. Never guess; ask the student which college if unclear.')
                ->required(),
            'run_type' => $schema->string()
                ->enum(['brainstorm', 'draft'])
                ->description('brainstorm = angles/themes; draft = expand seed into markdown draft.')
                ->required(),
            'message' => $schema->string()
                ->min(3)
                ->max($max)
                ->description('Brainstorm: what to explore (or full prompt if essay_prompt omitted). Draft: seed/outline/paragraph to expand.')
                ->required(),
            'essay_prompt' => $schema->string()
                ->min(10)
                ->max($max)
                ->description('Official essay question (required for draft; optional for brainstorm — defaults to message).')
                ->nullable()
                ->required(),
            'essay_type' => $schema->string()
                ->enum(EssayRun::essayTypes())
                ->description('common_app | supplemental | why_school')
                ->nullable()
                ->required(),
            'word_limit' => $schema->integer()
                ->description('Target max words if known.')
                ->nullable()
                ->required(),
            'tone' => $schema->string()
                ->enum(['brutally_honest', 'balanced', 'supportive'])
                ->description('Override mentor tone; null uses account default.')
                ->nullable()
                ->required(),
        ];
    }
}
