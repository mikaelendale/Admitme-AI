<?php

namespace App\Http\Controllers;

use App\Ai\CollegeListIntentDetector;
use App\Http\Requests\StoreCollegeListCouncilPreflightReplyRequest;
use App\Http\Requests\StoreCollegeListCouncilRunRequest;
use App\Jobs\RunCollegeListCouncilJob;
use App\Models\CollegeListRun;
use App\Services\CollegeListCouncil\CollegeListCouncilRunStarter;
use App\Support\FeatureQuotaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollegeListCouncilRunController extends Controller
{
    public function store(
        StoreCollegeListCouncilRunRequest $request,
        CollegeListCouncilRunStarter $starter,
    ): JsonResponse {
        if (! config('college_list_council.enabled')) {
            abort(404);
        }

        $user = $request->user();
        $message = trim((string) $request->validated('message'));
        $intentRaw = (string) ($request->validated('intent') ?? 'auto');
        $intent = in_array($intentRaw, ['auto', 'generate', 'refine', 'add', 'remove'], true)
            ? $intentRaw
            : 'auto';

        if ($intent === 'auto') {
            $intent = CollegeListIntentDetector::detect($message) ?? 'refine';
        }

        try {
            $run = $starter->start($user, $message, $intent);
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        app(FeatureQuotaManager::class)->record($user, 'college_list');

        return response()->json([
            'run_id' => (string) $run->id,
            'status' => $run->status,
            'intent' => $run->intent,
            'echo_channel' => $starter->privateChannelName($run),
            'poll_url' => route('college-lists.council-runs.show', ['run' => $run->id], false),
        ], 201);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        if (! config('college_list_council.enabled')) {
            abort(404);
        }

        $model = CollegeListRun::query()->find($run);
        if ($model === null) {
            abort(404);
        }

        if ((int) $model->user_id !== (int) $request->user()->id) {
            throw new AccessDeniedHttpException;
        }

        $events = $model->events()
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn ($e) => [
                'id' => (string) $e->id,
                'phase' => $e->phase,
                'severity' => $e->severity,
                'title' => $e->title,
                'body' => $e->body,
                'school_slug' => $e->school_slug,
                'created_at' => $e->created_at?->toIso8601String(),
            ]);

        $meta = is_array($model->meta) ? $model->meta : [];

        return response()->json([
            'run_id' => (string) $model->id,
            'status' => $model->status,
            'intent' => $model->intent,
            'summary_text' => $model->summary_text,
            'error_message' => $model->error_message,
            'events' => $events,
            'awaiting_preflight' => $model->status === CollegeListRun::STATUS_AWAITING_PREFLIGHT,
            'preflight_questions' => $model->status === CollegeListRun::STATUS_AWAITING_PREFLIGHT
                ? array_values(array_filter(Arr::wrap($meta['preflight_questions_pending'] ?? []), fn ($q) => is_string($q) && $q !== ''))
                : [],
            'preflight_timeout_seconds' => max(30, (int) config('college_list_council.preflight_auto_continue_seconds', 120)),
        ]);
    }

    public function preflightReply(
        StoreCollegeListCouncilPreflightReplyRequest $request,
        string $run,
    ): JsonResponse {
        if (! config('college_list_council.enabled')) {
            abort(404);
        }

        $model = CollegeListRun::query()->find($run);
        if ($model === null) {
            abort(404);
        }

        if ((int) $model->user_id !== (int) $request->user()->id) {
            throw new AccessDeniedHttpException;
        }

        if ($model->status !== CollegeListRun::STATUS_AWAITING_PREFLIGHT) {
            return response()->json([
                'message' => 'This run is not waiting for clarifications.',
            ], 409);
        }

        $reply = trim((string) $request->validated('reply', ''));

        $meta = array_merge($model->meta ?? [], [
            'preflight_resumed' => true,
            'preflight_auto_continued' => false,
            'preflight_student_reply' => $reply,
        ]);
        unset($meta['preflight_questions_pending']);

        $model->forceFill([
            'status' => CollegeListRun::STATUS_QUEUED,
            'meta' => $meta,
        ])->save();

        RunCollegeListCouncilJob::dispatch((string) $model->id);

        return response()->json([
            'ok' => true,
            'run_id' => (string) $model->id,
            'status' => $model->status,
        ]);
    }
}
