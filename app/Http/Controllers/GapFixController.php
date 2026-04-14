<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGapFixGenerateRequest;
use App\Http\Requests\StoreGapFixWhatIfExplainRequest;
use App\Models\CollegeListSchool;
use App\Models\GapFixAnalysis;
use App\Notifications\GapFixReadyNotification;
use App\Services\GapFix\GapFixAnalysisService;
use App\Services\GapFix\GapFixWhatIfExplainService;
use App\Support\FeatureQuotaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class GapFixController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $schools = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->where('is_removed', false)
            ->orderByRaw("CASE tier WHEN 'reach' THEN 1 WHEN 'match' THEN 2 WHEN 'safety' THEN 3 ELSE 4 END")
            ->orderBy('school_name')
            ->get(['id', 'school_name', 'tier', 'fit_score', 'median_sat', 'median_gpa', 'median_act', 'acceptance_rate'])
            ->map(fn (CollegeListSchool $s) => [
                'id' => (string) $s->id,
                'school_name' => $s->school_name,
                'tier' => $s->tier,
                'fit_score' => $s->fit_score,
                'median_sat' => $s->median_sat,
                'median_gpa' => $s->median_gpa !== null ? (string) $s->median_gpa : null,
                'median_act' => $s->median_act,
                'acceptance_rate' => $s->acceptance_rate !== null ? (string) $s->acceptance_rate : null,
            ])
            ->values()
            ->all();

        $profile = $user->onboardingProfile;

        $latest = GapFixAnalysis::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        return Inertia::render('gap-fix', [
            'college_schools' => $schools,
            'latest_analysis' => $latest ? [
                'id' => (string) $latest->id,
                'selected_school_ids' => is_array($latest->selected_school_ids) ? $latest->selected_school_ids : [],
                'payload' => is_array($latest->payload) ? $latest->payload : [],
                'is_partial' => (bool) $latest->is_partial,
                'missing_school_ids' => is_array($latest->missing_school_ids) ? $latest->missing_school_ids : [],
                'created_at' => $latest->created_at?->toIso8601String(),
            ] : null,
            'profile_baseline' => [
                'sat_score' => $profile?->sat_score,
                'gpa_normalized_4' => $profile?->gpa_normalized_4 !== null ? (float) $profile->gpa_normalized_4 : null,
                'test_optional_status' => $profile?->test_optional_status,
            ],
        ]);
    }

    public function generate(StoreGapFixGenerateRequest $request, GapFixAnalysisService $service): JsonResponse
    {
        /** @var list<string> $ids */
        $ids = array_values($request->validated('college_list_school_ids'));

        $out = $service->generate($request->user(), $ids);
        $analysis = $out['analysis'];

        $user = $request->user();
        try {
            $user->notify(new GapFixReadyNotification(count($ids)));
        } catch (\Throwable $e) {
            Log::warning('Notification failed: '.$e->getMessage(), [
                'user_id' => $user->id,
            ]);
        }

        app(FeatureQuotaManager::class)->record($user, 'gap_analysis');

        return response()->json([
            'analysis' => [
                'id' => (string) $analysis->id,
                'selected_school_ids' => $analysis->selected_school_ids,
                'payload' => $analysis->payload,
                'is_partial' => $out['is_partial'],
                'missing_school_ids' => $out['missing_school_ids'],
                'created_at' => $analysis->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function whatIfExplain(StoreGapFixWhatIfExplainRequest $request, GapFixWhatIfExplainService $service): JsonResponse
    {
        /** @var list<string> $ids */
        $ids = array_values($request->validated('college_list_school_ids'));
        $satDelta = (int) $request->validated('sat_delta');
        $gpaDelta = (float) $request->validated('gpa_delta');

        $markdown = $service->explain($request->user(), $ids, $satDelta, $gpaDelta);

        app(FeatureQuotaManager::class)->record($request->user(), 'fix_roadmap');

        return response()->json([
            'narrative' => $markdown,
        ]);
    }
}
