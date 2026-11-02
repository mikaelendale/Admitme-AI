<?php

namespace App\Ai\Tools;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Domain\Approvals\DocumentValueProposals;
use App\Domain\Approvals\ProposalService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: propose accepting or rejecting values read from the student's documents, so they can decide
 * them in one approval card. Nothing reaches the profile until the student approves (all or some items).
 */
class ProposeDocumentValueDecisionsTool implements Tool
{
    public function __construct(
        protected User $student,
        protected ?string $conversationId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Propose accepting or rejecting values read from the student\'s documents (the ones ReadPendingDocumentValuesTool lists, by their id), so the student can decide them in one approval card instead of one by one on the Documents page. '
            .'accept saves the value to their profile — with edited_value only when the student told you the correct value; reject discards it. Use it when the student asks to go through, accept or reject these values: '
            .'recommend accepting a value when its quote clearly shows it (or it matches what the student told you) and rejecting one that is misread; when unsure, ask. Put every value of the request into ONE call. '
            .'This does NOT change anything: the student approves (all or some) in the chat card or on the Approvals page. Afterwards say it is waiting for their approval — never that it is saved.';
    }

    public function handle(Request $request): Stringable|string
    {
        $decisions = [];

        foreach ((array) ($request['decisions'] ?? []) as $decision) {
            if (! is_array($decision)) {
                continue;
            }

            $decisions[] = [
                'value_id' => is_numeric($decision['value_id'] ?? null) ? (int) $decision['value_id'] : null,
                'action' => is_string($decision['action'] ?? null) ? strtolower(trim($decision['action'])) : '',
                'edited_value' => is_scalar($decision['edited_value'] ?? null) ? (string) $decision['edited_value'] : null,
                'reason' => is_string($decision['reason'] ?? null) ? $decision['reason'] : null,
            ];
        }

        if ($decisions === []) {
            return $this->rejected(['No decisions were given.']);
        }

        try {
            $proposal = app(ProposalService::class)->proposeDocumentValueDecisions($this->student, $decisions, class_basename(AdmitMeGeneralAgent::class).'/'.class_basename(self::class), $this->conversationId);
        } catch (ValidationException $e) {
            return $this->rejected(array_values(array_unique(array_merge(...array_values($e->errors())))));
        }

        return json_encode([
            'status' => 'pending_approval',
            'proposal_id' => $proposal->id,
            'title' => $proposal->title,
            'proposed' => array_column($proposal->items, 'label'),
            'message' => 'Nothing has changed yet. The student must approve this proposal (all or some items) before any value reaches their profile.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'decisions' => $schema->array()
                ->items($schema->object([
                    'value_id' => $schema->integer()->description('The value\'s id from ReadPendingDocumentValuesTool.')->required(),
                    'action' => $schema->string()->enum(DocumentValueProposals::ACTIONS)->description('accept = save it to the profile; reject = discard it.')->required(),
                    'edited_value' => $schema->string()
                        ->description('accept only: the correct value the student gave you, saved instead of the value as read. Null saves it as read (and for reject).')
                        ->nullable()
                        ->required(),
                    'reason' => $schema->string()->description('Why, e.g. what the quote shows or what the student said. Null if obvious.')->nullable()->required(),
                ])->withoutAdditionalProperties())
                ->min(1)
                ->max(50)
                ->required(),
        ];
    }

    /**
     * @param  list<string>  $errors
     */
    private function rejected(array $errors): string
    {
        $waiting = app(DocumentValueProposals::class)->pending($this->student)
            ->take(40)
            ->map(fn (array $row) => ['id' => (int) $row['fact']->getKey(), 'label' => $row['label'], 'document' => $row['document']->title, 'page' => $row['page']])
            ->values()
            ->all();

        return json_encode([
            'status' => 'not_proposed',
            'errors' => $errors,
            'values_waiting' => $waiting,
            'message' => 'No proposal was created and nothing changed. Fix these and call the tool again with all the decisions.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
