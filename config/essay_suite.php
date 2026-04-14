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

];
