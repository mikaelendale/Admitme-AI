<?php

use App\Support\FeatureQuotaManager;

/**
 * Gap + Fix Engine — expensive LLM endpoints.
 *
 * Per-student usage caps are enforced by {@see config/feature_limits.php} and
 * {@see FeatureQuotaManager} (middleware on gap-fix routes).
 */
return [

    'fake' => filter_var(
        env('GAP_FIX_FAKE', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
