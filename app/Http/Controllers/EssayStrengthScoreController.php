<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEssayScoreOnlyRequest;
use App\Services\Essay\EssayStrengthScoreService;
use App\Support\FeatureQuotaManager;
use Illuminate\Http\JsonResponse;

class EssayStrengthScoreController extends Controller
{
    /**
     * Essay strength score (cliché detector) — plan quotas in {@see FeatureQuotaManager}.
     */
    public function store(StoreEssayScoreOnlyRequest $request, EssayStrengthScoreService $service): JsonResponse
    {
        $payload = $service->score($request->user(), (string) $request->validated('essay_text'));

        app(FeatureQuotaManager::class)->record($request->user(), 'cliche_detector');

        return response()->json([
            'payload' => $payload,
        ], 201);
    }
}
