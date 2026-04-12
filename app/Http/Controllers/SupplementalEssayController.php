<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplementalEssayRequest;
use App\Http\Requests\UpdateSupplementalEssayRequest;
use App\Models\CollegeListSchool;
use App\Models\SupplementalEssay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SupplementalEssayController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $rows = SupplementalEssay::query()
            ->where('user_id', $user->id)
            ->with('collegeListSchool:id,school_name')
            ->orderBy('school_name')
            ->orderByDesc('due_date')
            ->orderByDesc('updated_at')
            ->get();

        $activeSchools = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderBy('school_name')
            ->get(['id', 'school_name', 'tier', 'fit_score'])
            ->map(fn (CollegeListSchool $s) => [
                'id' => (string) $s->id,
                'name' => $s->school_name,
                'tier' => $s->tier,
                'fit_score' => $s->fit_score,
            ])
            ->values()
            ->all();

        $serialized = $rows->map(fn (SupplementalEssay $e) => $this->serializeEssay($e))->values()->all();

        $grouped = [];
        foreach ($serialized as $item) {
            $key = $item['group_key'];
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'school_name' => $item['school_display_name'],
                    'items' => [],
                ];
            }
            $grouped[$key]['items'][] = $item;
        }

        $summary = [
            'total' => $rows->count(),
            'not_started' => $rows->where('status', SupplementalEssay::STATUS_NOT_STARTED)->count(),
            'in_progress' => $rows->where('status', SupplementalEssay::STATUS_IN_PROGRESS)->count(),
            'drafted' => $rows->where('status', SupplementalEssay::STATUS_DRAFTED)->count(),
            'final' => $rows->where('status', SupplementalEssay::STATUS_FINAL)->count(),
        ];

        return Inertia::render('essay-suite/supplements', [
            'supplement_groups' => array_values($grouped),
            'supplements_flat' => $serialized,
            'supplements_summary' => $summary,
            'active_schools' => $activeSchools,
            'supplements_nav_badge_count' => SupplementalEssay::navBadgeCountFor($user),
        ]);
    }

    public function store(StoreSupplementalEssayRequest $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validated();

        $essay = SupplementalEssay::query()->create([
            'user_id' => $user->id,
            'college_list_school_id' => $v['college_list_school_id'] ?? null,
            'school_name' => trim((string) $v['school_name']),
            'prompt_text' => trim((string) $v['prompt_text']),
            'word_limit' => isset($v['word_limit']) ? (int) $v['word_limit'] : null,
            'essay_type' => (string) $v['essay_type'],
            'status' => (string) $v['status'],
            'due_date' => $v['due_date'] ?? null,
        ]);

        $essay->load('collegeListSchool:id,school_name');

        return response()->json([
            'supplement' => $this->serializeEssay($essay),
        ], 201);
    }

    public function update(UpdateSupplementalEssayRequest $request, SupplementalEssay $supplement): JsonResponse
    {
        $this->authorizeSupplement($request, $supplement);

        $v = $request->validated();
        if (array_key_exists('content', $v)) {
            $supplement->content = $v['content'];
        }
        if (isset($v['status'])) {
            $supplement->status = (string) $v['status'];
        }
        if (array_key_exists('notes', $v)) {
            $supplement->notes = $v['notes'];
        }
        if (array_key_exists('word_limit', $v)) {
            $supplement->word_limit = $v['word_limit'];
        }
        if (array_key_exists('due_date', $v)) {
            $supplement->due_date = $v['due_date'];
        }
        $supplement->save();
        $supplement->load('collegeListSchool:id,school_name');

        return response()->json([
            'supplement' => $this->serializeEssay($supplement),
        ]);
    }

    public function destroy(Request $request, SupplementalEssay $supplement): JsonResponse
    {
        $this->authorizeSupplement($request, $supplement);
        $supplement->delete();

        return response()->json([], 204);
    }

    private function authorizeSupplement(Request $request, SupplementalEssay $supplement): void
    {
        if ((int) $supplement->user_id !== (int) $request->user()->id) {
            throw new AccessDeniedHttpException;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEssay(SupplementalEssay $e): array
    {
        $display = $e->collegeListSchool?->school_name ?? $e->school_name;
        $groupKey = $e->college_list_school_id !== null
            ? 'id:'.$e->college_list_school_id
            : 'name:'.mb_strtolower(trim($e->school_name));

        return [
            'id' => (string) $e->id,
            'group_key' => $groupKey,
            'school_display_name' => $display,
            'school_name' => $e->school_name,
            'college_list_school_id' => $e->college_list_school_id !== null ? (string) $e->college_list_school_id : null,
            'prompt_text' => $e->prompt_text,
            'word_limit' => $e->word_limit,
            'essay_type' => $e->essay_type,
            'status' => $e->status,
            'content' => $e->content,
            'notes' => $e->notes,
            'due_date' => $e->due_date?->toDateString(),
            'created_at' => $e->created_at?->toIso8601String(),
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }
}
