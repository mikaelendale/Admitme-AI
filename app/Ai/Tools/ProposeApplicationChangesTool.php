<?php

namespace App\Ai\Tools;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\ChecklistStatus;
use App\Domain\Applications\CompletionBasis;
use App\Domain\Applications\SubmissionEvidence;
use App\Domain\Approvals\ApplicationChangeProposals;
use App\Domain\Approvals\ProposalService;
use App\Models\Application;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: propose changes to the student's existing applications — status, notes, portal link,
 * application system, cycle, a checklist step's status, or a new checklist step. Nothing changes until the
 * student approves (all or some items).
 */
class ProposeApplicationChangesTool implements Tool
{
    public function __construct(
        protected User $student,
        protected ?string $conversationId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Propose changes to the student\'s EXISTING applications (read them first with ReadApplicationTool). Use it whenever the student asks to update an application instead of telling them to do it themselves: '
            .'action "update" sets one field — status ('.implode(', ', array_column(ApplicationStatus::cases(), 'value')).'), notes, portal_url, application_system or cycle_label (null clears); '
            .'action "checklist_status" sets a checklist step\'s status, naming the step by its title; action "add_checklist_item" adds a step (title, optional due_date YYYY-MM-DD and details). '
            .'Rules: propose status "submitted" only when the student said they submitted it, with submission_evidence_type and a note of what they said (a status the workspace cannot jump to directly goes through "ready to submit"). '
            .'A step someone else sends (recommendation, school report, score report) is "requested" when the student asked for it and "received" only with completion_basis (portal_shows_received or sender_confirmed); "blocked" and "not_applicable" need a note. '
            .'If a college has no application yet, propose start_application with ProposeCollegeListChangesTool first. Put everything the student asked for into ONE call. '
            .'This does NOT change anything: it creates a proposal the student approves (all or some items). Afterwards say it is waiting for their approval — never that it is done.';
    }

    public function handle(Request $request): Stringable|string
    {
        $changes = [];

        foreach ((array) ($request['changes'] ?? []) as $change) {
            if (! is_array($change)) {
                continue;
            }

            $text = fn (string $key) => is_scalar($change[$key] ?? null) ? (string) $change[$key] : null;

            $changes[] = [
                'college' => $text('college') ?? '',
                'action' => $text('action') ?? '',
                'field' => $text('field'),
                'value' => $text('value'),
                'submission_evidence_type' => $text('submission_evidence_type'),
                'checklist_item' => $text('checklist_item'),
                'checklist_status' => $text('checklist_status'),
                'completion_basis' => $text('completion_basis'),
                'note' => $text('note'),
                'title' => $text('title'),
                'due_date' => $text('due_date'),
                'details' => $text('details'),
                'reason' => $text('reason'),
            ];
        }

        if ($changes === []) {
            return $this->rejected(['No changes were given.']);
        }

        try {
            $proposal = app(ProposalService::class)->proposeApplicationChanges($this->student, $changes, class_basename(AdmitMeGeneralAgent::class).'/'.class_basename(self::class), $this->conversationId);
        } catch (ValidationException $e) {
            return $this->rejected(array_values(array_unique(array_merge(...array_values($e->errors())))));
        }

        $leftOut = count($changes) - count($proposal->items);

        return json_encode([
            'status' => 'pending_approval',
            'proposal_id' => $proposal->id,
            'title' => $proposal->title,
            'proposed' => array_column($proposal->items, 'label'),
            ...($leftOut > 0 ? ['left_out' => "{$leftOut} requested change(s) were left out because the application already has that value."] : []),
            'message' => 'Nothing has changed yet. The student must approve this proposal (all or some items) before their applications change.',
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
                    'college' => $schema->string()->description('The college whose application this changes, by name.')->required(),
                    'action' => $schema->string()->enum(ApplicationChangeProposals::ACTIONS)
                        ->description('update = set one application field; checklist_status = set a checklist step\'s status; add_checklist_item = add a step.')
                        ->required(),
                    'field' => $schema->string()->enum(array_keys(ApplicationChangeProposals::FIELDS))
                        ->description('action=update only: the field this change sets. Null otherwise.')
                        ->nullable()
                        ->required(),
                    'value' => $schema->string()
                        ->description('action=update only: the new value — status one of '.implode(', ', array_column(ApplicationStatus::cases(), 'value')).'; application_system one of '.implode(', ', Application::SYSTEMS).'; portal_url a full https:// link; notes replaces the saved notes (keep what should stay); null clears notes, portal_url, application_system or cycle_label. Null otherwise.')
                        ->nullable()
                        ->required(),
                    'submission_evidence_type' => $schema->string()->enum(SubmissionEvidence::class)
                        ->description('Required when the status change marks it submitted: portal_receipt if the student has the portal\'s confirmation, student_confirmation if they told you they submitted. Null otherwise.')
                        ->nullable()
                        ->required(),
                    'checklist_item' => $schema->string()
                        ->description('action=checklist_status only: the step\'s title as ReadApplicationTool shows it. Null otherwise.')
                        ->nullable()
                        ->required(),
                    'checklist_status' => $schema->string()->enum(ChecklistStatus::class)
                        ->description('action=checklist_status only: the student\'s own steps use todo, in_progress, done, blocked, not_applicable; steps someone else sends use not_requested, requested, received, blocked, not_applicable. Null otherwise.')
                        ->nullable()
                        ->required(),
                    'completion_basis' => $schema->string()->enum([CompletionBasis::PortalShowsReceived->value, CompletionBasis::SenderConfirmed->value])
                        ->description('Required to mark a step someone else sends "received": portal_shows_received or sender_confirmed, from what the student told you. Null otherwise.')
                        ->nullable()
                        ->required(),
                    'note' => $schema->string()
                        ->description('checklist_status blocked / not_applicable: what blocks it or why it is not needed (required). A submission: what the student told you about submitting (required). Otherwise null or an optional note.')
                        ->nullable()
                        ->required(),
                    'title' => $schema->string()->description('action=add_checklist_item only: the new step. Null otherwise.')->nullable()->required(),
                    'due_date' => $schema->string()->description('action=add_checklist_item only: due date as YYYY-MM-DD, or null.')->nullable()->required(),
                    'details' => $schema->string()->description('action=add_checklist_item only: optional details, or null.')->nullable()->required(),
                    'reason' => $schema->string()->description('Why, citing what the student said.')->required(),
                ])->withoutAdditionalProperties())
                ->min(1)
                ->max(30)
                ->required(),
        ];
    }

    /**
     * @param  list<string>  $errors
     */
    private function rejected(array $errors): string
    {
        $applications = Application::query()
            ->where('user_id', $this->student->getKey())
            ->with('collegeListSchool:id,school_name')
            ->get()
            ->map(fn (Application $application) => $application->collegeListSchool?->school_name)
            ->filter()
            ->sort()
            ->values()
            ->all();

        return json_encode([
            'status' => 'not_proposed',
            'errors' => $errors,
            'applications' => $applications,
            'message' => 'No proposal was created and nothing changed. Fix these and call the tool again with all the changes.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
