<?php

namespace App\Ai\Tools;

use App\Ai\CollegeListIntentDetector;
use App\Models\User;
use App\Services\CollegeListCouncil\CollegeListCouncilRunStarter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StartCollegeListCouncilJobTool implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Start AdmitMe's multi-agent college list council (background job): contextual audit inside the job, web-aware scouting, batched judging, then assembly into the student's saved AI list. Progress streams over Reverb; the tool response includes `run_id` and `echo_channel`. If the auditor needs optional clarifications, the live council card may pause and ask questions there—the list continues automatically after a timeout if the student does not reply.

Use for list builds and rebalance requests. Prefer this over ad-hoc single-school edits when many schools are involved.

Parameters: `message` (student request), `intent` (auto|generate|refine|add|remove).
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        if (! config('college_list_council.enabled')) {
            return json_encode([
                'ok' => false,
                'error' => 'College list council is disabled.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $message = trim($request->string('message')->toString());
        if ($message === '') {
            return json_encode(['ok' => false, 'error' => 'message is required.'], JSON_UNESCAPED_UNICODE);
        }

        $intentRaw = $request->string('intent')->toString();
        $intent = in_array($intentRaw, ['auto', 'generate', 'refine', 'add', 'remove'], true)
            ? $intentRaw
            : 'auto';

        if ($intent === 'auto') {
            $intent = CollegeListIntentDetector::detect($message) ?? 'refine';
        }

        $starter = app(CollegeListCouncilRunStarter::class);

        try {
            $run = $starter->start($this->user, $message, $intent);
        } catch (ConflictHttpException $e) {
            return json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'ok' => true,
            'council_started' => true,
            'run_id' => (string) $run->id,
            'status' => $run->status,
            'intent_used' => $intent,
            'echo_channel' => $starter->privateChannelName($run),
            'poll_url' => '/college-lists/council-runs/'.(string) $run->id,
            'college_lists_url' => '/college-lists?run='.(string) $run->id,
            'message' => 'Your college list council is running. Watch the live feed in chat; answer optional questions in the council card if shown. You can also open College lists for the full timeline.',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->max(4000)
                ->description('Student request or concise restatement.')
                ->required(),
            'intent' => $schema->string()
                ->enum(['auto', 'generate', 'refine', 'add', 'remove'])
                ->description('auto = infer from message.')
                ->required(),
        ];
    }
}
