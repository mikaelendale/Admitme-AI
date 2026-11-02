<?php

namespace App\Http\Controllers;

use App\Domain\Applications\ApplicationWorkspace;
use App\Domain\Applications\CollegeListService;
use App\Domain\Discovery\CollegeAssessor;
use App\Domain\Shared\ChangeSource;
use App\Http\Requests\StoreManualCollegeListSchoolRequest;
use App\Http\Requests\UpdateCollegeListSchoolRequest;
use App\Models\CollegeListRun;
use App\Models\CollegeListSchool;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CollegeListsController extends Controller
{
    public function index(Request $request, CollegeAssessor $assessor): Response
    {
        $user = $request->user();

        $councilRows = $user
            ->collegeListSchools()
            ->active()
            ->with('application:id,college_list_school_id')
            ->orderByRaw("CASE tier WHEN 'reach' THEN 1 WHEN 'match' THEN 2 WHEN 'safety' THEN 3 ELSE 4 END")
            ->orderBy('school_name')
            ->get();

        $assessments = $assessor->assessMany($user, $councilRows);

        $colleges = $councilRows
            ->map(fn (CollegeListSchool $s) => $this->councilSchoolToPageRow($s, $assessments[(string) $s->id] ?? null))
            ->values()
            ->all();

        $councilEnabled = (bool) config('college_list_council.enabled');

        return Inertia::render('college-lists', [
            'colleges' => $colleges,
            'councilRunId' => $councilEnabled
                ? $request->query('run')
                : null,
            'councilRunHistory' => $councilEnabled ? $this->councilRunHistoryForUser($user->id) : [],
        ]);
    }

    /**
     * @return list<array{id: string, status: string, summary_text: string|null, completed_at: string|null, school_count: int}>
     */
    protected function councilRunHistoryForUser(int $userId): array
    {
        return CollegeListRun::query()
            ->where('user_id', $userId)
            ->whereIn('status', [CollegeListRun::STATUS_COMPLETED, CollegeListRun::STATUS_FAILED])
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get()
            ->map(function (CollegeListRun $r) use ($userId): array {
                $schoolCount = CollegeListSchool::query()
                    ->where('user_id', $userId)
                    ->where('college_list_run_id', $r->id)
                    ->count();

                return [
                    'id' => (string) $r->id,
                    'status' => $r->status,
                    'summary_text' => $r->summary_text,
                    'completed_at' => $r->completed_at?->toIso8601String(),
                    'school_count' => $schoolCount,
                ];
            })
            ->all();
    }

    public function storeManual(StoreManualCollegeListSchoolRequest $request, CollegeListService $colleges): JsonResponse
    {
        $notes = $request->validated('student_notes');

        $school = $colleges->addManual(
            $request->user(),
            (string) $request->validated('school_name'),
            (string) $request->validated('tier'),
            $notes !== null ? (string) $notes : null,
            ChangeSource::studentManual($request->user(), 'college-lists.schools.manual'),
        );

        return response()->json(['ok' => true, 'id' => (string) $school->id, 'revision' => $school->revision], 201);
    }

    public function update(
        UpdateCollegeListSchoolRequest $request,
        CollegeListSchool $collegeListSchool,
        CollegeListService $colleges,
    ): JsonResponse {
        Gate::authorize('update', $collegeListSchool);

        $data = $request->validated();
        $expectedRevision = $data['expected_revision'] ?? null;
        unset($data['expected_revision']);

        $school = $colleges->update(
            $collegeListSchool,
            $data,
            $expectedRevision !== null ? (int) $expectedRevision : null,
            ChangeSource::studentManual($request->user(), 'college-lists.school.update'),
        );

        return response()->json([
            'ok' => true,
            'revision' => $school->revision,
            'choiceStatus' => $school->choice_status->value,
            'applicationStatus' => $school->application_status ?? 'not_started',
            'applicationId' => $school->application()->value('id'),
        ]);
    }

    public function destroy(Request $request, CollegeListSchool $collegeListSchool, CollegeListService $colleges): JsonResponse
    {
        Gate::authorize('delete', $collegeListSchool);

        $expectedRevision = $request->integer('expected_revision') ?: null;

        $colleges->archive(
            $collegeListSchool,
            $expectedRevision,
            ChangeSource::studentManual($request->user(), 'college-lists.school.destroy'),
        );

        return response()->json(['ok' => true]);
    }

    public function show(Request $request, CollegeListSchool $collegeListSchool, CollegeAssessor $assessor): Response
    {
        Gate::authorize('view', $collegeListSchool);

        return Inertia::render('college-lists/school', [
            'school' => $this->councilSchoolToPageRow($collegeListSchool, $assessor->assess($request->user(), $collegeListSchool)),
        ]);
    }

    /**
     * Add some research suggestions to the shortlist and dismiss others in one decision.
     */
    public function reviewSuggestions(Request $request, CollegeListService $colleges): JsonResponse
    {
        $validated = $request->validate([
            'add' => ['present', 'array', 'max:50'],
            'add.*' => ['string', 'max:26'],
            'dismiss' => ['present', 'array', 'max:50'],
            'dismiss.*' => ['string', 'max:26'],
        ]);

        if ($validated['add'] === [] && $validated['dismiss'] === []) {
            return response()->json(['message' => 'Choose at least one suggestion.', 'errors' => ['add' => ['Choose at least one suggestion.']]], 422);
        }

        $receipt = $colleges->reviewSuggestions(
            $request->user(),
            array_values($validated['add']),
            array_values($validated['dismiss']),
            ChangeSource::studentManual($request->user(), 'college-lists.suggestions.review'),
        );

        return response()->json(['ok' => true, ...$receipt]);
    }

    /**
     * Side-by-side view of up to four of the student's own colleges.
     */
    public function compare(Request $request, CollegeAssessor $assessor, ApplicationWorkspace $workspace): Response
    {
        $ids = array_slice(array_values(array_filter(explode(',', (string) $request->query('ids', '')))), 0, 4);

        $schools = CollegeListSchool::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('id', $ids)
            ->with('application')
            ->get()
            ->sortBy(fn (CollegeListSchool $s) => array_search((string) $s->id, $ids, true))
            ->values();

        $assessments = $assessor->assessMany($request->user(), $schools);

        return Inertia::render('college-lists/compare', [
            'colleges' => $schools->map(fn (CollegeListSchool $s) => [
                ...$this->councilSchoolToPageRow($s, $assessments[(string) $s->id] ?? null),
                'application' => $s->application !== null ? $workspace->summary($s->application) : null,
            ])->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $assessment
     * @return array<string, mixed>
     */
    protected function councilSchoolToPageRow(CollegeListSchool $s, ?array $assessment = null): array
    {
        $deadline = collect([
            $s->ea_deadline,
            $s->ed_deadline,
            $s->rd_deadline,
        ])->filter()->min();

        $deadlineSort = $deadline instanceof CarbonInterface ? (int) $deadline->format('n') : 12;
        $deadlineLabel = $deadline instanceof CarbonInterface
            ? $deadline->format('M j, Y').' — nearest deadline'
            : '— No deadline set';

        $deadlinesDisplay = $this->formatDeadlinesMultiline($s);

        $meta = is_array($s->student_metadata) ? $s->student_metadata : [];

        $city = $s->location_city;
        $state = $s->location_state;
        $locationFromMeta = (string) ($meta['location'] ?? '');
        $location = ($city || $state)
            ? trim(implode(', ', array_filter([(string) $city, (string) $state])))
            : ($locationFromMeta !== '' ? $locationFromMeta : '—');

        $aidEstimate = match ($s->aid_estimate_band) {
            'strong' => 'Strong aid likelihood',
            'moderate' => 'Moderate aid',
            'limited' => 'Limited aid',
            default => (string) ($meta['aid_estimate'] ?? '—'),
        };

        $netCost = (string) ($meta['net_cost_estimate'] ?? '—');

        return [
            'id' => (string) $s->id,
            'name' => $s->school_name,
            'location' => $location,
            'tier' => $s->tier,
            'fitScore' => $s->fit_score ?? 0,
            'deadline' => $deadlineLabel,
            'deadlinesDisplay' => $deadlinesDisplay,
            'aidEstimate' => $aidEstimate !== '' ? $aidEstimate : '—',
            'netCostEstimate' => $netCost !== '' ? $netCost : '—',
            'whyFit' => $s->why_blurb !== '' ? $s->why_blurb : '—',
            'deadlineSort' => $deadlineSort,
            'applicationPortalUrl' => $s->application_portal_url ?? '',
            'admissionsCycleLabel' => $s->admissions_cycle_label ?? '',
            'applicationStatus' => $s->application_status ?? 'not_started',
            'studentNotes' => $s->student_notes ?? '',
            'choiceStatus' => $s->choice_status->value,
            'revision' => (int) $s->revision,
            'applicationId' => $s->application?->id,
            'runId' => $s->college_list_run_id !== null ? (string) $s->college_list_run_id : null,
            'notablePrograms' => array_values((array) ($s->notable_programs ?? [])),
            'assessment' => $assessment,
        ];
    }

    protected function formatDeadlinesMultiline(CollegeListSchool $s): string
    {
        $lines = [];
        if ($s->ea_deadline instanceof CarbonInterface) {
            $lines[] = 'EA: '.$s->ea_deadline->format('M j, Y');
        }
        if ($s->ed_deadline instanceof CarbonInterface) {
            $lines[] = 'ED: '.$s->ed_deadline->format('M j, Y');
        }
        if ($s->rd_deadline instanceof CarbonInterface) {
            $lines[] = 'RD: '.$s->rd_deadline->format('M j, Y');
        }

        return $lines !== [] ? implode("\n", $lines) : '— No deadlines saved';
    }
}
