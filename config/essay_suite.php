<?php

use App\Jobs\ProcessEssayRunJob;

return [

    /**
     * When true, {@see ProcessEssayRunJob} skips LLM calls and writes stub output (tests / local).
     */
    'fake' => filter_var(
        env('ESSAY_SUITE_FAKE', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'max_prompt_length' => max(500, min(16000, (int) env('ESSAY_SUITE_MAX_PROMPT', 8000))),

    /** Max POST /essay-suite/roast per user per hour (RateLimiter: essay-roast). */
    'roast_per_user_per_hour' => max(1, min(60, (int) env('ESSAY_SUITE_ROAST_PER_HOUR', 5))),

    /** Max POST /essay-suite/why-this-school per user per hour (RateLimiter: essay-why-school). */
    'why_school_per_user_per_hour' => max(1, min(120, (int) env('ESSAY_SUITE_WHY_SCHOOL_PER_HOUR', 10))),

    /** Max POST /essay-suite/score-only per user per hour (RateLimiter: essay-score-only). */
    'score_only_per_user_per_hour' => max(1, min(120, (int) env('ESSAY_SUITE_SCORE_ONLY_PER_HOUR', 10))),

];
