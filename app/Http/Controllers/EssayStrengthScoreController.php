<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEssayScoreOnlyRequest;
use App\Services\Essay\EssayStrengthScoreService;
use Illuminate\Http\JsonResponse;

class EssayStrengthScoreController extends Controller
{
    /**
     * Essay strength score only — throttle: essay-score-only (see AppServiceProvider, config/essay_suite.php).
     */
    public function store(StoreEssayScoreOnlyRequest $request, EssayStrengthScoreService $service): JsonResponse
    {
        $payload = $service->score($request->user(), (string) $request->validated('essay_text'));

        return response()->json([
            'payload' => $payload,
        ], 201);
    }
}
