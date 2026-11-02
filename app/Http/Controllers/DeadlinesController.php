<?php

namespace App\Http\Controllers;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The deadline tracker: every college on the student's list with its Early Action, Early Decision and Regular
 * Decision dates (or rolling admission), and the deadlines official sources put on each application's checklist,
 * as one calendar. A college with none of these needs one — the page asks for it.
 */
class DeadlinesController extends Controller
{
    /** The rounds a college can have, as the calendar labels them. */
    private const ROUNDS = [
        'ea_deadline' => ['ea', 'Early Action'],
        'ed_deadline' => ['ed', 'Early Decision'],
        'rd_deadline' => ['rd', 'Regular Decision'],
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $schools = CollegeListSchool::query()
            ->where('user_id', $user->getKey())
            ->whereIn('choice_status', [CollegeChoiceStatus::Shortlisted->value, CollegeChoiceStatus::Applying->value])
            ->with('application:id,college_list_school_id,status')
            ->orderBy('school_name')
            ->get();

        $events = [];
        $colleges = [];

        foreach ($schools as $school) {
            $dates = [];

            foreach (self::ROUNDS as $field => [$kind, $label]) {
                $date = $school->getAttribute($field)?->toDateString();
                $dates[$kind] = $date;

                if ($date !== null) {
                    $events[] = [
                        'id' => "list:{$school->id}:{$kind}",
                        'collegeId' => (string) $school->id,
                        'college' => $school->school_name,
                        'kind' => $kind,
                        'label' => $label,
                        'date' => $date,
                        // Dates on the list come from the student, research or the mentor: not checked against a source.
                        'source' => 'list',
                    ];
                }
            }

            $rolling = (bool) $school->rolling_admission;

            $colleges[] = [
                'id' => (string) $school->id,
                'name' => $school->school_name,
                'tier' => $school->tier,
                'choice' => $school->choice_status->value,
                'ea' => $dates['ea'],
                'ed' => $dates['ed'],
                'rd' => $dates['rd'],
                'rolling' => $rolling,
                'needsDeadline' => ! $rolling && array_filter($dates) === [],
                'revision' => (int) $school->revision,
                'applicationId' => $school->application?->id !== null ? (string) $school->application->id : null,
                'href' => "/college-lists/{$school->id}",
            ];
        }

        // Deadlines official sources put on an application's checklist (open steps only).
        $steps = ApplicationItem::query()
            ->where('user_id', $user->getKey())
            ->where('kind', 'deadline')
            ->whereNotNull('due_date')
            ->whereIn('application_id', $schools->pluck('application.id')->filter()->values())
            ->with('application:id,college_list_school_id')
            ->get();

        $names = $schools->keyBy(fn (CollegeListSchool $school) => (string) $school->id);

        foreach ($steps as $step) {
            if ($step->status->isClosed()) {
                continue;
            }

            $schoolId = (string) $step->application?->college_list_school_id;

            $events[] = [
                'id' => "step:{$step->id}",
                'collegeId' => $schoolId,
                'college' => $names->get($schoolId)?->school_name ?? 'Application',
                'kind' => 'step',
                'label' => $step->title,
                'date' => $step->due_date->toDateString(),
                'source' => 'official',
                'href' => "/applications/{$step->application_id}",
            ];
        }

        usort($events, fn (array $a, array $b) => [$a['date'], $a['college']] <=> [$b['date'], $b['college']]);

        return Inertia::render('deadlines', [
            'today' => now()->toDateString(),
            'colleges' => $colleges,
            'events' => $events,
        ]);
    }
}
