<?php

return [

    /**
     * Override for council / list agents (e.g. "2026–27"). When empty, derived from the server year.
     */
    'applying_cycle_label' => env('ADMISSIONS_APPLYING_CYCLE_LABEL'),

    'max_profile_words' => (int) env('ADMISSIONS_MAX_PROFILE_WORDS', 2000),

    /** Enable OpenAI web search tool on AdmitMeGeneralAgent (requires provider support). */
    'general_agent_web_search' => (bool) env('ADMITME_GENERAL_AGENT_WEB_SEARCH', true),

    /** When true, PromptingAgent / StreamingAgent / AgentStreamed / tool events log at debug. */
    'log_ai_sdk_events' => (bool) env('ADMITME_LOG_AI_SDK_EVENTS', false),

];
