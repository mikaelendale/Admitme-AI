<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManualCollegeListSchoolRequest;
use App\Http\Requests\UpdateCollegeListSchoolRequest;
use App\Models\CollegeListRun;
use App\Models\CollegeListSchool;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CollegeListsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $councilRows = $user
            ->collegeListSchools()
            ->active()
            ->orderByRaw("CASE tier WHEN 'reach' THEN 1 WHEN 'match' THEN 2 WHEN 'safety' THEN 3 ELSE 4 END")
            ->orderBy('school_name')
            ->get();

        $colleges = $councilRows
            ->map(fn (CollegeListSchool $s) => $this->councilSchoolToPageRow($s))
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

    public function storeManual(StoreManualCollegeListSchoolRequest $request): JsonResponse
    {
        $user = $request->user();
        $name = trim((string) $request->validated('school_name'));
        $tier = (string) $request->validated('tier');
        $notes = $request->validated('student_notes');

        $slug = Str::slug($name);
        if ($slug === '') {
            return response()->json(['message' => 'Could not derive a school identifier from that name.'], 422);
        }

        $exists = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->where('school_slug', $slug)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'A school with a similar name is already on your list.'], 422);
        }

        CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => $name,
            'school_slug' => $slug,
            'tier' => $tier,
            'fit_score' => 50,
            'why_blurb' => '',
            'student_notes' => $notes !== null && $notes !== '' ? (string) $notes : null,
            'application_status' => 'not_started',
            'is_removed' => false,
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function update(
        UpdateCollegeListSchoolRequest $request,
        CollegeListSchool $collegeListSchool,
    ): JsonResponse {
        $user = $request->user();
        if ((int) $collegeListSchool->user_id !== (int) $user->id || $collegeListSchool->is_removed) {
            abort(404);
        }

        $data = $request->validated();
        if (array_key_exists('application_status', $data)) {
            $collegeListSchool->application_status = $data['application_status'];
        }
        if (array_key_exists('student_notes', $data)) {
            $collegeListSchool->student_notes = $data['student_notes'];
        }
        $collegeListSchool->save();

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, CollegeListSchool $collegeListSchool): JsonResponse
    {
        $user = $request->user();
        if ((int) $collegeListSchool->user_id !== (int) $user->id || $collegeListSchool->is_removed) {
            abort(404);
        }

        $collegeListSchool->is_removed = true;
        $collegeListSchool->save();

        return response()->json(['ok' => true]);
    }

    public function show(Request $request, CollegeListSchool $collegeListSchool): Response
    {
        $user = $request->user();
        if ((int) $collegeListSchool->user_id !== (int) $user->id || $collegeListSchool->is_removed) {
            abort(404);
        }

        return Inertia::render('college-lists/school', [
            'school' => $this->councilSchoolToPageRow($collegeListSchool),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function councilSchoolToPageRow(CollegeListSchool $s): array
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
