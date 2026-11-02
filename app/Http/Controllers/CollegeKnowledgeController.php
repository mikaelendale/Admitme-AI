<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Knowledge\KnowledgeIngestionService;
use App\Domain\Knowledge\RequirementOverview;
use App\Domain\Knowledge\ResearchService;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\ResearchAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Requirements, sources and source-backed research for a college on the student's own list.
 */
class CollegeKnowledgeController extends Controller
{
    public function show(Request $request, CollegeListSchool $collegeListSchool, InstitutionResolver $institutions, RequirementOverview $overview): JsonResponse
    {
        Gate::authorize('view', $collegeListSchool);

        $cycle = $this->cycle($request, $collegeListSchool);

        return response()->json([
            'cycle' => $cycle,
            ...$overview->for($institutions->findForSchool($collegeListSchool), $cycle, $request->user()),
            'answers' => ResearchAnswer::query()
                ->where('user_id', $request->user()->getKey())
                ->where('college_list_school_id', $collegeListSchool->id)
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (ResearchAnswer $answer) => $this->present($answer))
                ->values(),
        ]);
    }

    public function addSource(Request $request, CollegeListSchool $collegeListSchool, InstitutionResolver $institutions, KnowledgeIngestionService $ingestion, RequirementOverview $overview): JsonResponse
    {
        Gate::authorize('update', $collegeListSchool);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'official' => ['sometimes', 'boolean'],
        ]);

        $source = $ingestion->ingest($institutions->forSchool($collegeListSchool), $validated['url'], $request->user(), (bool) ($validated['official'] ?? false));

        if ($source->status === 'failed') {
            return response()->json(['message' => $source->failure_reason, 'errors' => ['url' => [$source->failure_reason]]], 422);
        }

        $cycle = $this->cycle($request, $collegeListSchool);

        return response()->json(['cycle' => $cycle, ...$overview->for($institutions->findForSchool($collegeListSchool), $cycle, $request->user())], 201);
    }

    public function ask(Request $request, CollegeListSchool $collegeListSchool, ResearchService $research): JsonResponse
    {
        Gate::authorize('view', $collegeListSchool);

        $validated = $request->validate(['question' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json(['answer' => $this->present($research->answer($request->user(), $collegeListSchool, $validated['question']))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ResearchAnswer $answer): array
    {
        return [
            'id' => (string) $answer->id,
            'question' => $answer->question,
            'answer' => $answer->answer,
            'claims' => $answer->claims,
            'unresolved' => $answer->unresolved,
            'evidence' => $answer->evidence,
            'cycle' => $answer->cycle_label,
            'createdAt' => $answer->created_at?->toIso8601String(),
        ];
    }

    private function cycle(Request $request, CollegeListSchool $school): ?string
    {
        return OnboardingProfile::query()->where('user_id', $request->user()->getKey())->value('target_intake') ?: $school->admissions_cycle_label;
    }
}
