<?php

use App\Ai\Tools\RunCollegeListAgentTool;
use App\Services\CollegeListCouncil\FakeCollegeListCouncilOrchestrator;

/*
|--------------------------------------------------------------------------
| Operator rollout (Reverb + queue)
|--------------------------------------------------------------------------
|
| Enable the council with COLLEGE_LIST_COUNCIL_ENABLED=true, set BROADCAST_CONNECTION=reverb,
| configure REVERB_* and VITE_REVERB_* for builds, and run `php artisan reverb:start` (or
| Supervisor) alongside your queue worker. For emergency synchronous list generation in chat,
| set COLLEGE_LIST_SYNC_AGENT_FALLBACK=true so RunCollegeListAgentTool stays registered.
|
*/

return [

    'enabled' => filter_var(
        env('COLLEGE_LIST_COUNCIL_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /**
     * When true, the in-job auditor may pause the run for optional clarifications (council card UI);
     * {@see CollegeListCouncilAutoContinuePreflightJob} resumes after {@see preflight_auto_continue_seconds}.
     * Set false in tests or to always run straight through.
     */
    'pause_for_preflight_in_job' => filter_var(
        env('COLLEGE_LIST_PAUSE_PREFLIGHT_IN_JOB', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /** Seconds before the council auto-continues without student reply (minimum 30). */
    'preflight_auto_continue_seconds' => max(30, (int) env('COLLEGE_LIST_PREFLIGHT_AUTO_CONTINUE_SECONDS', 120)),

    /**
     * When council is enabled, also register synchronous {@see RunCollegeListAgentTool}
     * for emergencies / debugging.
     */
    'sync_fallback' => filter_var(
        env('COLLEGE_LIST_SYNC_AGENT_FALLBACK', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /** When true (e.g. in tests), {@see FakeCollegeListCouncilOrchestrator} runs instead of LLM phases. */
    'fake_phases' => filter_var(
        env('COLLEGE_LIST_COUNCIL_FAKE_PHASES', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'scout_max_web_searches' => (int) env('COLLEGE_LIST_SCOUT_MAX_SEARCHES', 5),

    'scout_max_web_fetches' => (int) env('COLLEGE_LIST_SCOUT_MAX_FETCHES', 3),

    /** Max “Scout found …” lines pushed to the live feed per run. */
    'max_scout_insight_events' => (int) env('COLLEGE_LIST_MAX_SCOUT_INSIGHTS', 8),

    /** Schools per judge LLM batch. */
    'judge_batch_size' => (int) env('COLLEGE_LIST_JUDGE_BATCH', 10),

    /** Target candidates from scout before judging. */
    'scout_target_candidates' => (int) env('COLLEGE_LIST_SCOUT_CANDIDATES', 24),

    /** Final list size after assembly. */
    'final_list_max' => (int) env('COLLEGE_LIST_FINAL_MAX', 12),

    'final_list_min' => (int) env('COLLEGE_LIST_FINAL_MIN', 8),

    /** Optional narrative summary via LLM (extra cost). */
    'llm_summary_enabled' => env('COLLEGE_LIST_COUNCIL_LLM_SUMMARY', false),

    'job_timeout' => (int) env('COLLEGE_LIST_COUNCIL_JOB_TIMEOUT', 600),

    'job_tries' => (int) env('COLLEGE_LIST_COUNCIL_JOB_TRIES', 1),

    /**
     * Per-call OpenAI HTTP timeouts (seconds) for council agents.
     * Laravel AI defaults to 60s when not overridden — too low for tool-heavy scout/judge calls.
     * Keep each value below your queue worker max timeout and below {@see job_timeout}.
     */
    'auditor_llm_timeout' => max(60, (int) env('COLLEGE_LIST_AUDITOR_LLM_TIMEOUT', 180)),

    'scout_llm_timeout' => max(120, (int) env('COLLEGE_LIST_SCOUT_LLM_TIMEOUT', 420)),

    'judge_llm_timeout' => max(120, (int) env('COLLEGE_LIST_JUDGE_LLM_TIMEOUT', 300)),

    /** Log preflight / scout / judge / assembly milestones (counts + durations). */
    'milestone_logging' => filter_var(
        env('COLLEGE_LIST_COUNCIL_LOG_MILESTONES', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /**
     * Optional {@see config/logging.php} channel (e.g. college_list_council) so council lines
     * land in storage/logs/college-list-council.log instead of only laravel.log.
     */
    'milestone_log_channel' => env('COLLEGE_LIST_COUNCIL_LOG_CHANNEL'),

];
