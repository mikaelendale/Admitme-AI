<?php

namespace App\Http\Controllers;

use App\Models\EssayRun;
use App\Models\SupplementalEssay;
use App\Models\WhyThisSchoolDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EssaySuiteController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $schools = $user
            ->collegeListSchools()
            ->active()
            ->orderBy('school_name')
            ->get(['id', 'school_name', 'tier', 'fit_score'])
            ->map(fn ($s) => [
                'id' => (string) $s->id,
                'name' => $s->school_name,
                'tier' => $s->tier,
                'fit_score' => $s->fit_score,
            ])
            ->values()
            ->all();

        $whySchoolDrafts = WhyThisSchoolDraft::query()
            ->where('user_id', $user->id)
            ->with('collegeListSchool:id,school_name')
            ->orderByDesc('created_at')
            ->limit(80)
            ->get()
            ->map(function (WhyThisSchoolDraft $d): array {
                $draft = is_array($d->payload) ? ($d->payload['draft'] ?? '') : '';

                return [
                    'id' => (string) $d->id,
                    'college_list_school_id' => (string) $d->college_list_school_id,
                    'school_name' => $d->collegeListSchool?->school_name ?? 'School',
                    'created_at' => $d->created_at?->toIso8601String(),
                    'draft_preview' => Str::limit(is_string($draft) ? $draft : '', 160),
                ];
            })
            ->values()
            ->all();

        $prefs = is_array($user->ai_preferences) ? $user->ai_preferences : [];
        $mentorTone = is_string($prefs['mentor_tone'] ?? null) ? $prefs['mentor_tone'] : 'balanced';

        $recentRuns = EssayRun::query()
            ->where('user_id', $user->id)
            ->with('collegeListSchool:id,school_name')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (EssayRun $r) => [
                'id' => (string) $r->id,
                'run_type' => $r->run_type,
                'status' => $r->status,
                'college_list_school_id' => (string) $r->college_list_school_id,
                'school_name' => $r->collegeListSchool?->school_name ?? 'School',
                'title_for_card' => $r->title_for_card,
                'user_prompt_excerpt' => Str::limit($r->user_prompt, 140),
                'created_at' => $r->created_at?->toIso8601String(),
                'has_output' => $r->output_markdown !== null && $r->output_markdown !== '',
            ])
            ->values()
            ->all();

        return Inertia::render('essay-suite', [
            'essay_schools' => $schools,
            'essay_active_schools' => $schools,
            'essay_recent_runs' => $recentRuns,
            'essay_why_school_drafts' => $whySchoolDrafts,
            'supplements_nav_badge_count' => SupplementalEssay::navBadgeCountFor($user),
            'essay_defaults' => [
                'mentor_tone' => $mentorTone,
            ],
        ]);
    }
}
