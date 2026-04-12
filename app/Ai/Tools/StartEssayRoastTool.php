<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\Essay\EssayRoastService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Roasts an essay the student pastes into chat, calling {@see EssayRoastService}.
 */
class StartEssayRoastTool implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Roast / critique a college application essay the student pastes in chat. Returns scores for
authenticity, specificity, emotional_impact, structure, and differentiation (each 0–10),
plus cliché detection, structural and voice issues, and a top-priority fix.

**Hard requirements — do not skip:**
1. The student **must** paste their full essay text. If they haven't: ask them to paste it.
2. Minimum 100 characters, maximum 8000.
3. Ask the essay type (common_app | supplemental | why_school) if not clear from context.

On success, the roast breakdown card appears below your message automatically.

Parameters: `essay_text`, `essay_type`, optional `school_name`.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $essayText = trim($request->string('essay_text')->toString());
            if (strlen($essayText) < 100) {
                return json_encode([
                    'ok' => false,
                    'error' => 'Essay text must be at least 100 characters. Ask the student to paste the full essay.',
                ], JSON_UNESCAPED_UNICODE);
            }

            if (strlen($essayText) > 8000) {
                return json_encode([
                    'ok' => false,
                    'error' => 'Essay text must be at most 8000 characters. Ask the student to trim it.',
                ], JSON_UNESCAPED_UNICODE);
            }

            $essayType = trim($request->string('essay_type')->toString());
            if (! in_array($essayType, ['common_app', 'supplemental', 'why_school'], true)) {
                $essayType = 'supplemental';
            }

            $schoolNameRaw = $request->input('school_name');
            $schoolName = is_string($schoolNameRaw) ? trim($schoolNameRaw) : null;
            if ($schoolName === '') {
                $schoolName = null;
            }

            $service = app(EssayRoastService::class);
            $result = $service->roast($this->user, $essayText, null, $essayType);

            $payload = $result['payload'];
            $scores = is_array($payload['scores'] ?? null) ? $payload['scores'] : [];
            $overallVerdict = (string) ($payload['overall_verdict'] ?? '');
            $topPriorityFix = (string) ($payload['top_priority_fix'] ?? '');
            $cliches = is_array($payload['cliches'] ?? null) ? $payload['cliches'] : [];

            $verdictFirstSentence = $overallVerdict;
            $dotPos = strpos($overallVerdict, '.');
            if ($dotPos !== false && $dotPos < 200) {
                $verdictFirstSentence = substr($overallVerdict, 0, $dotPos + 1);
            }

            return json_encode([
                'ok' => true,
                'roast_ready' => true,
                'roast_id' => $result['id'],
                'overall_verdict' => $overallVerdict,
                'scores' => $scores,
                'cliche_count' => count($cliches),
                'top_priority_fix' => $topPriorityFix,
                'chat_response' => 'Roast complete. Overall: '.$verdictFirstSentence.' Your biggest fix: '.$topPriorityFix.'. Full breakdown attached below.',
                'action_url' => '/essay-suite',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => 'Roast failed: '.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'essay_text' => $schema->string()
                ->min(100)
                ->max(8000)
                ->description('The full essay text to roast. Student must paste this in their message.')
                ->required(),
            'essay_type' => $schema->string()
                ->enum(['common_app', 'supplemental', 'why_school'])
                ->description('Type of essay being reviewed.')
                ->required(),
            'school_name' => $schema->string()
                ->max(200)
                ->description('School this essay is for, if applicable.')
                ->nullable()
                ->required(),
        ];
    }
}
