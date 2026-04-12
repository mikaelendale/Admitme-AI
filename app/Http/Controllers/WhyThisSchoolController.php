<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWhyThisSchoolRequest;
use App\Services\Essay\WhyThisSchoolService;
use Illuminate\Http\JsonResponse;

class WhyThisSchoolController extends Controller
{
    /**
     * Why this school draft — throttle: essay-why-school (see AppServiceProvider, config/essay_suite.php).
     */
    public function store(StoreWhyThisSchoolRequest $request, WhyThisSchoolService $service): JsonResponse
    {
        $out = $service->generate(
            $request->user(),
            (string) $request->validated('college_list_school_id'),
        );

        return response()->json([
            'id' => $out['id'],
            'payload' => $out['payload'],
        ], 201);
    }
}
