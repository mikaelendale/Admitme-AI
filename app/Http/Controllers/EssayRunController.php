<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEssayRunRequest;
use App\Models\EssayRun;
use App\Services\EssaySuite\EssayRunStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class EssayRunController extends Controller
{
    public function store(StoreEssayRunRequest $request, EssayRunStarter $starter): JsonResponse
    {
        $user = $request->user();
        $schoolId = (string) $request->validated('college_list_school_id');
        $runType = (string) $request->validated('run_type');
        $message = $request->studentMessage();

        try {
            $run = $starter->start($user, $schoolId, $runType, $message, $request->essayOptions());
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'run_id' => (string) $run->id,
            'status' => $run->status,
            'run_type' => $run->run_type,
            'poll_url' => route('essay-suite.runs.show', ['run' => $run->id], false),
        ], 201);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $model = EssayRun::query()->with('collegeListSchool')->find($run);
        if ($model === null) {
            abort(404);
        }

        if ((int) $model->user_id !== (int) $request->user()->id) {
            throw new AccessDeniedHttpException;
        }

        return response()->json([
            'run_id' => (string) $model->id,
            'status' => $model->status,
            'run_type' => $model->run_type,
            'title_for_card' => $model->title_for_card,
            'output_markdown' => $model->output_markdown,
            'error_message' => $model->error_message,
            'school_name' => $model->collegeListSchool?->school_name,
            'started_at' => $model->started_at?->toIso8601String(),
            'completed_at' => $model->completed_at?->toIso8601String(),
        ]);
    }
}
