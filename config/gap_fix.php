<?php

/**
 * Gap + Fix Engine — expensive LLM endpoints.
 *
 * generate: strict per-user hourly cap (students hammer "Regenerate").
 * what_if_explain: separate cap for optional narrative after sliders.
 */
return [

    'fake' => filter_var(
        env('GAP_FIX_FAKE', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /** Max successful generate requests per user per hour (RateLimiter name: gap-fix-generate). */
    'generate_per_user_per_hour' => max(1, min(20, (int) env('GAP_FIX_GENERATE_PER_HOUR', 4))),

    /** Max what-if explain (LLM) calls per user per hour (RateLimiter: gap-fix-what-if-explain). */
    'what_if_explain_per_user_per_hour' => max(1, min(60, (int) env('GAP_FIX_WHAT_IF_EXPLAIN_PER_HOUR', 10))),
];
