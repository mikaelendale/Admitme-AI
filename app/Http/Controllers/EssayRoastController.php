<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEssayRoastRequest;
use App\Services\Essay\EssayRoastService;
use App\Support\FeatureQuotaManager;
use Illuminate\Http\JsonResponse;

class EssayRoastController extends Controller
{
    /**
     * Essay suite roast — plan quotas in {@see FeatureQuotaManager}.
     */
    public function store(StoreEssayRoastRequest $request, EssayRoastService $service): JsonResponse
    {
        $user = $request->user();
        $v = $request->validated();
        $essayRunId = isset($v['essay_run_id']) && is_string($v['essay_run_id']) && $v['essay_run_id'] !== ''
            ? $v['essay_run_id']
            : null;
        $essayType =
            isset($v['essay_type']) && is_string($v['essay_type']) && $v['essay_type'] !== ''
                ? $v['essay_type']
                : null;

        $out = $service->roast(
            $user,
            (string) $v['essay_text'],
            $essayRunId,
            $essayType,
        );

        app(FeatureQuotaManager::class)->record($user, 'essay_roast');

        return response()->json([
            'id' => $out['id'],
            'payload' => $out['payload'],
        ], 201);
    }
}
