<?php

namespace App\Ai\Tools;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Approvals\CollegeListChangeProposals;
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
 * Agent mode: propose changes to colleges already on the student's list — saved deadlines (the deadline
 * tracker), tier, notes, portal link, cycle, progress — or remove one, start its application, or add an
 * official source page. Nothing changes until the student approves (all or some items).
 */
class ProposeCollegeListChangesTool implements Tool
{
    public function __construct(
        protected User $student,
        protected ?string $conversationId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Propose changes to colleges ALREADY on the student\'s saved list. Use it whenever the student asks to change their list instead of telling them to do it themselves: '
            .'set or correct deadlines (ea_deadline / ed_deadline / rd_deadline, YYYY-MM-DD — these fill the deadline tracker; every college should have at least one, or rolling_admission "true" when it decides on a rolling basis), tier (reach|match|safety), notes, application portal link, admissions cycle or progress (action "update", one field per change; value null clears it); '
            .'"remove" a college (archived, history kept); "start_application" (moves it to Applying and opens its application with a checklist); '
            .'"add_source" (an official admissions page — full https link — that is fetched and read for requirements after approval). '
            .'Put everything the student asked for into ONE call (several changes, across colleges). Use the college names from the list (GetStudentCollegeList). '
            .'Never invent values: use dates and links the student gave you or that you found on an official page, and cite that page in evidence. '
            .'This does NOT change anything: it creates a proposal the student approves (all or some items) in the chat card or on the Approvals page. Afterwards say it is waiting for their approval — never that it is done. '
            .'New colleges: ProposeCollegeListAdditionsTool. Application status and checklist steps: ProposeApplicationChangesTool.';
    }

    public function handle(Request $request): Stringable|string
    {
        $changes = [];

        foreach ((array) ($request['changes'] ?? []) as $change) {
            if (! is_array($change)) {
                continue;
            }

            $changes[] = [
                'college' => is_string($change['college'] ?? null) ? $change['college'] : '',
                'action' => is_string($change['action'] ?? null) ? $change['action'] : '',
                'field' => is_string($change['field'] ?? null) ? $change['field'] : null,
                'value' => is_scalar($change['value'] ?? null) ? (string) $change['value'] : null,
                'url' => is_string($change['url'] ?? null) ? $change['url'] : null,
                'official' => filter_var($change['official'] ?? false, FILTER_VALIDATE_BOOL),
                'reason' => is_string($change['reason'] ?? null) ? $change['reason'] : null,
                'evidence' => self::evidence($change['evidence'] ?? null),
            ];
        }

        if ($changes === []) {
            return $this->rejected(['No changes were given.']);
        }

        try {
            $proposal = app(ProposalService::class)->proposeCollegeListChanges($this->student, $changes, class_basename(AdmitMeGeneralAgent::class).'/'.class_basename(self::class), $this->conversationId);
        } catch (ValidationException $e) {
            return $this->rejected(array_values(array_unique(array_merge(...array_values($e->errors())))));
        }

        $leftOut = count($changes) - count($proposal->items);

        return json_encode([
            'status' => 'pending_approval',
            'proposal_id' => $proposal->id,
            'title' => $proposal->title,
            'proposed' => array_column($proposal->items, 'label'),
            ...($leftOut > 0 ? ['left_out' => "{$leftOut} requested change(s) were left out because the list already has that value."] : []),
            'message' => 'Nothing has changed yet. The student must approve this proposal (all or some items) before their college list changes.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'changes' => $schema->array()
                ->items($schema->object([
                    'college' => $schema->string()->description('The college\'s name as it appears on the student\'s list.')->required(),
                    'action' => $schema->string()->enum(CollegeListChangeProposals::ACTIONS)
                        ->description('update = set one saved field; remove = take it off the list (archived, history kept); start_application = move it to Applying and open its application; add_source = add an official admissions page as a source.')
                        ->required(),
                    'field' => $schema->string()->enum(array_keys(CollegeListChangeProposals::FIELDS))
                        ->description('action=update only: the field this change sets. Null for other actions.')
                        ->nullable()
                        ->required(),
                    'value' => $schema->string()
                        ->description('action=update only: the new value — deadlines as YYYY-MM-DD; rolling_admission "true" or "false"; tier reach|match|safety; application_status one of '.implode(', ', CollegeListSchool::APPLICATION_STATUSES).'; application_portal_url a full https:// link; student_notes replaces the saved notes (keep what should stay); null clears the field. Null for other actions.')
                        ->nullable()
                        ->required(),
                    'url' => $schema->string()
                        ->description('action=add_source only: the page\'s full https:// link. Null otherwise.')
                        ->nullable()
                        ->required(),
                    'official' => $schema->boolean()
                        ->description('action=add_source only: true when the student confirmed this page is the college\'s official website (unless the domain is verified it is then kept private to them and marked not verified). Null otherwise.')
                        ->nullable()
                        ->required(),
                    'reason' => $schema->string()->description('Why, citing what the student said or where the value comes from.')->required(),
                    'evidence' => $schema->array()
                        ->items($schema->object([
                            'text' => $schema->string()->description('What the source says, e.g. "Regular Decision: January 15, 2027".')->required(),
                            'basis' => $schema->string()->description('Where it is from, e.g. "Official admissions website" or "The student told me".')->required(),
                            'url' => $schema->string()->description('The page it is from, or null.')->nullable()->required(),
                        ])->withoutAdditionalProperties())
                        ->max(4)
                        ->description('What the new value rests on, shown to the student with the change. Null when the student told you directly.')
                        ->nullable()
                        ->required(),
                ])->withoutAdditionalProperties())
                ->min(1)
                ->max(40)
                ->required(),
        ];
    }

    /**
     * @return list<array{text: string, basis: string, url: string|null}>|null
     */
    private static function evidence(mixed $evidence): ?array
    {
        if (! is_array($evidence)) {
            return null;
        }

        $points = [];

        foreach ($evidence as $point) {
            if (is_array($point) && is_string($point['text'] ?? null) && is_string($point['basis'] ?? null)) {
                $points[] = ['text' => $point['text'], 'basis' => $point['basis'], 'url' => is_string($point['url'] ?? null) ? $point['url'] : null];
            }
        }

        return $points === [] ? null : $points;
    }

    /**
     * @param  list<string>  $errors
     */
    private function rejected(array $errors): string
    {
        $names = CollegeListSchool::query()
            ->where('user_id', $this->student->getKey())
            ->where('choice_status', '!=', CollegeChoiceStatus::Archived->value)
            ->orderBy('school_name')
            ->pluck('school_name')
            ->all();

        return json_encode([
            'status' => 'not_proposed',
            'errors' => $errors,
            'colleges_on_list' => $names,
            'message' => 'No proposal was created and nothing changed. Fix these and call the tool again with all the changes.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
