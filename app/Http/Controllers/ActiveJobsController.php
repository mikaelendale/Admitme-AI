<?php

namespace App\Http\Controllers;

use App\Models\JobTracking;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActiveJobsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $active = JobTracking::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [JobTracking::STATUS_PENDING, JobTracking::STATUS_RUNNING])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (JobTracking $t) => [
                'id' => $t->id,
                'type' => $t->job_type,
                'label' => $t->label(),
                'status' => $t->status,
                'started_at' => $t->started_at?->toIso8601String(),
                'started_ago' => $t->startedAgo(),
            ]);

        $recentFailed = JobTracking::query()
            ->where('user_id', $user->id)
            ->where('status', JobTracking::STATUS_FAILED)
            ->where('failed_at', '>=', now()->subHours(24))
            ->orderByDesc('failed_at')
            ->limit(10)
            ->get()
            ->map(fn (JobTracking $t) => [
                'id' => $t->id,
                'type' => $t->job_type,
                'label' => $t->label(),
                'status' => $t->status,
                'failed_at' => $t->failed_at?->toIso8601String(),
                'error_message' => $t->error_message,
                'retry_url' => $t->retryUrl(),
            ]);

        return response()->json([
            'active' => $active,
            'recent_failed' => $recentFailed,
        ]);
    }
}
