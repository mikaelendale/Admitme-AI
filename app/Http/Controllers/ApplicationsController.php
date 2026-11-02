<?php

namespace App\Http\Controllers;

use App\Domain\Applications\ApplicationChecklistService;
use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\ApplicationWorkspace;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Applications\CollegeListService;
use App\Domain\Applications\SubmissionEvidence;
use App\Domain\Browser\BrowserSessionPresenter;
use App\Domain\Shared\ChangeSource;
use App\Domain\Tasks\Orchestrator;
use App\Models\Application;
use App\Models\ApplicationItem;
use App\Models\BrowserSession;
use App\Models\CollegeListSchool;
use App\Models\ExtensionToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $applications = Application::query()
            ->where('user_id', $user->getKey())
            ->with('collegeListSchool')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Application $application) => $this->row($application))
            ->values()
            ->all();

        $startable = CollegeListSchool::query()
            ->where('user_id', $user->getKey())
            ->where('choice_status', CollegeChoiceStatus::Shortlisted)
            ->whereDoesntHave('application')
            ->orderBy('school_name')
            ->get(['id', 'school_name', 'tier', 'revision'])
            ->map(fn (CollegeListSchool $school) => [
                'id' => (string) $school->id,
                'name' => $school->school_name,
                'tier' => $school->tier,
                'revision' => (int) $school->revision,
            ])
            ->values()
            ->all();

        return Inertia::render('applications', [
            'applications' => $applications,
            'startable' => $startable,
            'statusOptions' => array_map(fn (ApplicationStatus $s) => ['value' => $s->value, 'label' => $s->label()], ApplicationStatus::cases()),
            'systemOptions' => Application::SYSTEMS,
            'evidenceOptions' => array_map(fn (SubmissionEvidence $e) => ['value' => $e->value, 'label' => $e->label()], SubmissionEvidence::cases()),
        ]);
    }

    /**
     * Start an application for a shortlisted college (moves the choice to "applying").
     */
    public function store(Request $request, CollegeListService $colleges): RedirectResponse
    {
        $validated = $request->validate([
            'college_list_school_id' => ['required', 'string', Rule::exists('college_list_schools', 'id')],
            'expected_revision' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $school = CollegeListSchool::query()->findOrFail($validated['college_list_school_id']);

        Gate::authorize('update', $school);

        $colleges->update(
            $school,
            ['choice_status' => CollegeChoiceStatus::Applying->value],
            isset($validated['expected_revision']) ? (int) $validated['expected_revision'] : null,
            ChangeSource::studentManual($request->user(), 'applications.store'),
        );

        return redirect()->route('applications.index')->with('status', 'application-started');
    }

    public function update(Request $request, Application $application, ApplicationService $applications): RedirectResponse
    {
        Gate::authorize('update', $application);

        $expectedRevision = $request->validate([
            'expected_revision' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])['expected_revision'] ?? null;

        $applications->update(
            $application,
            $request->only(['status', 'application_system', 'portal_url', 'cycle_label', 'notes', 'submission_evidence_type', 'submission_evidence_note']),
            $expectedRevision !== null ? (int) $expectedRevision : null,
            ChangeSource::studentManual($request->user(), 'applications.update'),
        );

        return ($request->boolean('from_workspace') ? redirect()->route('applications.show', $application) : redirect()->route('applications.index'))
            ->with('status', 'application-saved');
    }

    /**
     * The application workspace: checklist from source-backed requirements, deadline, blockers, next actions.
     */
    public function show(Request $request, Application $application, ApplicationWorkspace $workspace, ApplicationChecklistService $checklist): Response
    {
        Gate::authorize('view', $application);

        // Applications opened before checklists existed get one on first view...
        if ($application->checklist_built_at === null) {
            $checklist->build($application, ChangeSource::studentManual($request->user(), 'applications.show'));
            $application->refresh();
        }

        return Inertia::render('applications/show', [
            'workspace' => $workspace->for($application),
            'statusOptions' => array_map(fn (ApplicationStatus $s) => ['value' => $s->value, 'label' => $s->label()], ApplicationStatus::cases()),
            'nextStatuses' => array_values(array_map(
                fn (ApplicationStatus $s) => $s->value,
                array_filter(ApplicationStatus::cases(), fn (ApplicationStatus $s) => $application->status->canTransitionTo($s)),
            )),
            'evidenceOptions' => array_map(fn (SubmissionEvidence $e) => ['value' => $e->value, 'label' => $e->label()], SubmissionEvidence::cases()),
            'systemOptions' => Application::SYSTEMS,
            'kindOptions' => ApplicationItem::KINDS,
            'browser' => fn () => $this->browserAssistance($request, $application),
        ]);
    }

    /**
     * The extension's latest session on this application's form (if any), so the student can follow and
     * approve fills here as well as in the side panel.
     *
     * @return array{linked: bool, session: array<string, mixed>|null}
     */
    private function browserAssistance(Request $request, Application $application): array
    {
        $session = BrowserSession::query()
            ->where('user_id', $request->user()->getKey())
            ->where('application_id', $application->getKey())
            ->latest('updated_at')
            ->first();

        return [
            'linked' => ExtensionToken::query()->where('user_id', $request->user()->getKey())->whereNull('revoked_at')->where('expires_at', '>', now())->exists(),
            'session' => $session !== null ? BrowserSessionPresenter::present($session) : null,
        ];
    }

    /**
     * Rebuild the checklist from the requirements now on file; progress on existing steps is kept.
     */
    public function rebuildChecklist(Request $request, Application $application, ApplicationChecklistService $checklist): RedirectResponse
    {
        Gate::authorize('update', $application);

        $counts = $checklist->build($application, ChangeSource::studentManual($request->user(), 'applications.checklist.rebuild'));

        return back()->with('status', "checklist-rebuilt:{$counts['created']}:{$counts['updated']}:{$counts['flagged']}");
    }

    /**
     * Start a one-step plan that prepares the field-by-field form plan (runs in the background).
     */
    public function formPlan(Request $request, Application $application, Orchestrator $orchestrator): RedirectResponse
    {
        Gate::authorize('update', $application);
        $application->loadMissing('collegeListSchool');

        $orchestrator->plan(
            $request->user(),
            'Plan the application form for '.$application->collegeListSchool->school_name,
            [['key' => 'plan', 'type' => 'application_plan', 'input' => ['application_id' => (string) $application->getKey()]]],
            'student',
            null,
            'form-plan:'.$application->getKey().':'.now()->format('YmdHi'),
        );

        return back()->with('status', 'form-plan-started');
    }

    public function storeItem(Request $request, Application $application, ApplicationChecklistService $checklist): RedirectResponse
    {
        Gate::authorize('update', $application);

        $checklist->addManual(
            $application,
            $request->only(['title', 'kind', 'owner_type', 'owner_label', 'due_date', 'details']),
            ChangeSource::studentManual($request->user(), 'applications.items.store'),
        );

        return back()->with('status', 'item-added');
    }

    public function updateItem(Request $request, Application $application, ApplicationItem $item, ApplicationChecklistService $checklist): RedirectResponse
    {
        Gate::authorize('update', $application);
        abort_unless((string) $item->application_id === (string) $application->getKey(), 404);

        $expectedRevision = $request->validate([
            'expected_revision' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])['expected_revision'] ?? null;

        $checklist->update(
            $item,
            $request->only(['status', 'status_note', 'completion_basis', 'document_id', 'due_date', 'owner_label', 'title', 'details']),
            $expectedRevision !== null ? (int) $expectedRevision : null,
            ChangeSource::studentManual($request->user(), 'applications.items.update'),
        );

        return back()->with('status', 'item-saved');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Application $application): array
    {
        $school = $application->collegeListSchool;

        return [
            'id' => (string) $application->id,
            'revision' => (int) $application->revision,
            'status' => $application->status->value,
            'statusLabel' => $application->status->label(),
            'nextStatuses' => array_values(array_map(
                fn (ApplicationStatus $s) => $s->value,
                array_filter(ApplicationStatus::cases(), fn (ApplicationStatus $s) => $application->status->canTransitionTo($s)),
            )),
            'applicationSystem' => $application->application_system,
            'portalUrl' => $application->portal_url,
            'cycleLabel' => $application->cycle_label,
            'notes' => $application->notes,
            'submittedAt' => $application->submitted_at?->toIso8601String(),
            'submissionEvidenceType' => $application->submission_evidence_type?->value,
            'submissionEvidenceNote' => $application->submission_evidence_note,
            'college' => [
                'id' => (string) $school->id,
                'name' => $school->school_name,
                'tier' => $school->tier,
                'choiceStatus' => $school->choice_status->value,
                'deadlines' => array_filter([
                    'EA' => $school->ea_deadline?->toDateString(),
                    'ED' => $school->ed_deadline?->toDateString(),
                    'RD' => $school->rd_deadline?->toDateString(),
                ]),
            ],
        ];
    }
}
