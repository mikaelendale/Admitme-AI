<?php

namespace App\Ai\Tools;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Domain\Approvals\ProposalService;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: propose colleges to add to the student's saved list. Nothing is added until the student
 * approves; they can approve only some of them.
 */
class ProposeCollegeListAdditionsTool implements Tool
{
    public function __construct(
        protected User $user,
        protected ?string $conversationId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Propose one or more colleges to add to the student\'s saved list, each with a tier and a short reason. This does NOT add them: the student reviews the proposal and may approve all, some, or none. Afterwards say the colleges are waiting for their approval — never that they were added.';
    }

    public function handle(Request $request): Stringable|string
    {
        $colleges = [];

        foreach ((array) ($request['colleges'] ?? []) as $college) {
            if (! is_array($college) || ! is_string($college['school_name'] ?? null)) {
                continue;
            }

            $colleges[] = [
                'school_name' => $college['school_name'],
                'tier' => CollegeListSchool::listTypeToTier((string) ($college['list_type'] ?? '')),
                'reason' => $college['reason'] ?? null,
            ];
        }

        try {
            $proposal = app(ProposalService::class)->proposeCollegeAdditions($this->user, $colleges, class_basename(AdmitMeGeneralAgent::class).'/'.class_basename(self::class), $this->conversationId);
        } catch (ValidationException $e) {
            return json_encode([
                'status' => 'not_proposed',
                'errors' => array_merge(...array_values($e->errors())),
                'message' => 'No proposal was created and nothing changed.',
            ], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        $proposed = array_column($proposal->items, 'label');

        return json_encode([
            'status' => 'pending_approval',
            'proposal_id' => $proposal->id,
            'title' => $proposal->title,
            'proposed' => $proposed,
            'already_on_list' => array_values(array_diff(array_column($colleges, 'school_name'), $proposed)),
            'message' => 'Nothing has been added yet. The student must approve the colleges they want.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'colleges' => $schema->array()
                ->items($schema->object([
                    'school_name' => $schema->string()->description('Official-style name, e.g. "University of Toronto".')->required(),
                    'list_type' => $schema->string()->enum(CollegeListSchool::LIST_TYPES)->required(),
                    'reason' => $schema->string()->description('Why it fits, from the student\'s saved facts and stated wishes.')->required(),
                ])->withoutAdditionalProperties())
                ->min(1)
                ->max(20)
                ->required(),
        ];
    }
}
