<?php

namespace App\Ai\Tools;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Domain\Approvals\ProposalService;
use App\Domain\Students\StudentFieldRegistry;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: propose profile changes for the student to review. It never changes the profile itself.
 */
class ProposeProfileChangesTool implements Tool
{
    /**
     * Fields an agent may propose. Structured activity/award lists are edited by the student directly.
     *
     * @var list<string>
     */
    private const EXCLUDED = ['activities', 'awards'];

    public function __construct(
        protected User $user,
        protected ?string $conversationId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Propose changes to the student\'s saved profile (for example a corrected SAT score or a new intended major). This does NOT change anything: it creates a proposal the student must approve in the chat card or on the Approvals page. Afterwards say the change is waiting for their approval — never that it is done. Give a short reason for each change, based on what the student said.';
    }

    public function handle(Request $request): Stringable|string
    {
        $changes = [];

        foreach ((array) ($request['changes'] ?? []) as $change) {
            if (! is_array($change) || ! is_string($change['field'] ?? null)) {
                continue;
            }

            $field = $change['field'];

            if (! StudentFieldRegistry::has($field) || in_array($field, self::EXCLUDED, true)) {
                return $this->rejected(["{$field} cannot be proposed."]);
            }

            $changes[$field] = [
                'value' => $this->coerce($field, $change['value'] ?? null),
                'reason' => $change['reason'] ?? null,
            ];
        }

        if ($changes === []) {
            return $this->rejected(['No valid changes were given.']);
        }

        try {
            $proposal = app(ProposalService::class)->proposeProfileChanges($this->user, $changes, class_basename(AdmitMeGeneralAgent::class).'/'.class_basename(self::class), $this->conversationId);
        } catch (ValidationException $e) {
            return $this->rejected(array_merge(...array_values($e->errors())));
        }

        return json_encode([
            'status' => 'pending_approval',
            'proposal_id' => $proposal->id,
            'title' => $proposal->title,
            'message' => 'Nothing has changed yet. The student must approve this proposal before the profile is updated.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = array_values(array_diff(array_keys(StudentFieldRegistry::fields()), self::EXCLUDED));

        return [
            'changes' => $schema->array()
                ->items($schema->object([
                    'field' => $schema->string()->enum($fields)->required(),
                    'value' => $schema->string()
                        ->description('New value as text. Numbers as digits; lists as a JSON array or comma-separated; null to clear.')
                        ->nullable()
                        ->required(),
                    'reason' => $schema->string()->description('Why, citing what the student said.')->required(),
                ])->withoutAdditionalProperties())
                ->min(1)
                ->max(10)
                ->required(),
        ];
    }

    private function coerce(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (StudentFieldRegistry::get($field)['type']) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'list' => is_array($decoded = json_decode((string) $value, true))
                ? $decoded
                : array_map('trim', preg_split('/[,\n]/', (string) $value) ?: []),
            default => $value,
        };
    }

    /**
     * @param  list<string>  $errors
     */
    private function rejected(array $errors): string
    {
        return json_encode([
            'status' => 'not_proposed',
            'errors' => $errors,
            'message' => 'No proposal was created and nothing changed.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
