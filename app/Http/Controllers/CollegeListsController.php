<?php

namespace App\Http\Controllers;

use App\Models\CollegeListSchool;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
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

        return Inertia::render('college-lists', [
            'colleges' => $colleges,
            'councilRunId' => config('college_list_council.enabled')
                ? $request->query('run')
                : null,
        ]);
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
