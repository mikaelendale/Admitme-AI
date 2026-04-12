<?php

namespace App\Ai\Tools;

use App\Ai\Agents\CollegeListAgent;
use App\Ai\CollegeListIntentDetector;
use App\Models\CollegeListSchool;
use App\Models\User;
use App\Services\CollegeList\CollegeListAssemblyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Invokes the structured {@see CollegeListAgent} (sub-agent) to generate or refine
 * the student's AI college list ({@see CollegeListSchool}), then persists the result.
 */
class RunCollegeListAgentTool implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Run AdmitMe's specialized college-list agent: it uses the student's profile plus web tools when needed, returns a structured list (reach/match/safety, fit, deadlines, aid band estimates), and saves rows to their AI college list in the database.

Use this when the student wants to build, regenerate, rebalance, or substantially edit their list in one step (multiple schools, new reach/match/safety mix, "make me a list", "swap safeties", "add more matches", etc.).

For adding or updating a single school row by hand, use UpsertStudentCollegeList. After this tool succeeds, summarize the assistant-facing `chat_response` from the JSON for the student and mention they can review everything on /college-lists.

Parameters:
- `message`: Pass the student's exact request (or your concise restatement) — this is what the list agent optimizes against.
- `intent`: Use `auto` to infer from the message; or force `generate`, `refine`, `add`, or `remove` when the student's goal is unambiguous.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
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

        if (! Schema::hasTable('college_list_schools')) {
            return json_encode([
                'ok' => false,
                'error' => 'College list storage is not available yet. Run database migrations (php artisan migrate), then try again.',
            ], JSON_UNESCAPED_UNICODE);
        }

        try {
            $agent = new CollegeListAgent($this->user, $intent);
            $response = $agent->prompt($message);
            $payload = $this->structuredToArray($response);
        } catch (Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => 'College list agent failed: '.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }

        if (! is_array($payload)) {
            return json_encode(['ok' => false, 'error' => 'Invalid response from college list agent.'], JSON_UNESCAPED_UNICODE);
        }

        $validationError = $this->collegeListPayloadValidationError($intent, $payload);
        if ($validationError !== null) {
            Log::warning('RunCollegeListAgentTool: college list payload validation failed', [
                'user_id' => $this->user->id,
                'intent' => $intent,
                'reason' => $validationError,
                'payload_shape' => [
                    'keys' => array_keys($payload),
                    'action' => $payload['action'] ?? null,
                    'schools_count' => is_array($payload['schools'] ?? null) ? count($payload['schools']) : null,
                    'removed_slugs_count' => is_array($payload['removed_slugs'] ?? null) ? count($payload['removed_slugs']) : null,
                    'first_school_keys' => isset($payload['schools'][0]) && is_array($payload['schools'][0])
                        ? array_keys($payload['schools'][0])
                        : null,
                ],
            ]);

            return json_encode([
                'ok' => false,
                'error' => 'College list payload validation failed: '.$validationError,
            ], JSON_UNESCAPED_UNICODE);
        }

        try {
            app(CollegeListAssemblyService::class)->persistFromAgentPayload($this->user, $intent, $payload, null);
        } catch (Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => 'Could not save list: '.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }

        $chatResponse = (string) ($payload['chat_response'] ?? 'Your college list was updated.');
        $schools = is_array($payload['schools'] ?? null) ? $payload['schools'] : [];
        $activeCount = CollegeListSchool::query()
            ->where('user_id', $this->user->id)
            ->active()
            ->count();

        return json_encode([
            'ok' => true,
            'chat_response' => $chatResponse,
            'intent_used' => $intent,
            'schools_in_response' => count($schools),
            'active_schools_total' => $activeCount,
            'college_lists_path' => '/college-lists',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<string, mixed>
     */
    protected function structuredToArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            /** @var array<string, mixed> $out */
            $out = $response->toArray();

            return $out;
        }

        if ($response instanceof \Traversable) {
            return iterator_to_array($response, true);
        }

        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return json_decode($json ?: '[]', true) ?: [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function collegeListPayloadValidationError(string $intent, array $payload): ?string
    {
        $action = $payload['action'] ?? 'refine';
        $willWipeList = $intent === 'generate' || $action === 'generate';

        $schools = $payload['schools'] ?? [];
        if (! is_array($schools)) {
            return 'schools must be an array.';
        }

        if ($willWipeList && $schools === []) {
            return 'generate requires a non-empty schools array.';
        }

        $validTiers = ['reach', 'match', 'safety'];
        foreach ($schools as $index => $row) {
            if (! is_array($row)) {
                return 'schools['.$index.'] must be an object-shaped row.';
            }
            $name = isset($row['school_name']) ? trim((string) $row['school_name']) : '';
            $tier = isset($row['tier']) ? (string) $row['tier'] : '';
            if ($name === '' || ! in_array($tier, $validTiers, true)) {
                return 'schools['.$index.'] missing non-empty school_name or valid tier.';
            }
        }

        return null;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->max(4000)
                ->description('Student request or your concise restatement for the college list agent.')
                ->required(),
            'intent' => $schema->string()
                ->enum(['auto', 'generate', 'refine', 'add', 'remove'])
                ->description('auto = infer from message; otherwise force this intent.')
                ->required(),
        ];
    }
}
