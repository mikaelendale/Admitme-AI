<?php

/*
|--------------------------------------------------------------------------
| AdmitMe agent runtime
|--------------------------------------------------------------------------
|
| Application-level contracts from docs/admitme/phased-build-plan.md. Token figures are
| estimates (characters / 4) used to keep assembled context near a target; they are not
| provider token counts. Provider usage is recorded separately after each run.
|
*/

return [

    'ai' => [
        // Provider for every AdmitMe agent unless overridden. It needs a working key in config/ai.php.
        'provider' => env('ADMITME_AI_PROVIDER', 'openai'),

        // Default model per provider.
        'models' => [
            'openai' => env('ADMITME_OPENAI_MODEL', 'gpt-5-nano'),
            'anthropic' => env('ADMITME_ANTHROPIC_MODEL', 'claude-haiku-5-5'),
            'gemini' => env('ADMITME_GEMINI_MODEL', 'gemini-3.7-flash'),
        ],

        // Per-agent overrides keyed by class basename, e.g. 'CouncilJudgeAgent' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'].
        'agents' => [],

        // When an agent's provider cannot answer (no credits, overloaded, rate limited), try the next provider that
        // has a key, in this order, with that provider's model for the agent. Logged as a warning each time.
        'failover' => (bool) env('ADMITME_AI_FAILOVER', true),
        'failover_providers' => ['anthropic', 'openai', 'gemini'],
    ],

    'mentor' => [
        // The mentor is the student's main conversation and plans with tools, so it runs on a stronger model
        // than the background agents (the smallest models skipped tools and invented schools in testing).
        // Claude is used when an Anthropic key is configured; otherwise the global provider. Set
        // ADMITME_MENTOR_PROVIDER / ADMITME_MENTOR_MODEL to choose explicitly.
        'provider' => env('ADMITME_MENTOR_PROVIDER', env('ANTHROPIC_API_KEY') ? 'anthropic' : null),
        'model' => env('ADMITME_MENTOR_MODEL'),

        // The mentor's model per provider when ADMITME_MENTOR_MODEL is not set (falls back to admitme.ai.models).
        'models' => [
            'anthropic' => env('ADMITME_MENTOR_ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'openai' => env('ADMITME_MENTOR_OPENAI_MODEL', 'gpt-5.5'),
        ],

        'max_steps' => (int) env('ADMITME_MENTOR_MAX_STEPS', 16),

        // Steps per reply while the student has given the mentor control of a browser tab (Agent mode): each
        // portal page takes a read, a fill and a click, and the student asked for the work to just get done.
        'browser_max_steps' => (int) env('ADMITME_MENTOR_BROWSER_MAX_STEPS', 40),

        // Ask is read-only; Agent keeps the existing tools until Phase 3 converts writes to proposals.
        'default_mode' => env('ADMITME_MENTOR_DEFAULT_MODE', 'ask'),
    ],

    'context' => [
        // Target for assembled instructions + memory + history for an ordinary turn.
        'target_tokens' => (int) env('ADMITME_CONTEXT_TARGET_TOKENS', 8000),

        // Budget for the student memory brief inside the instructions.
        'memory_tokens' => (int) env('ADMITME_CONTEXT_MEMORY_TOKENS', 1800),

        // Budget for replayed conversation history. Whole stored turns are dropped, oldest first.
        'history_tokens' => (int) env('ADMITME_CONTEXT_HISTORY_TOKENS', 4000),

        // The most recent turns are always kept, even over budget, so a just-used tool result never disappears.
        'min_history_turns' => 2,

        // Rows inspected when choosing which turns fit.
        'max_history_rows' => 100,

        // Bumped when the shape of memory projections changes, so cached projections are rebuilt.
        'memory_schema_version' => 2,

        'memory_cache_ttl_seconds' => 3600,
    ],

    'documents' => [
        // Text recognition for scans/photos. Null = not available (uploads fail with a manual-entry route).
        'ocr_driver' => env('ADMITME_OCR_DRIVER'),
    ],

    'knowledge' => [
        // A source not re-checked within this many days is shown as stale.
        'stale_after_days' => (int) env('ADMITME_KNOWLEDGE_STALE_DAYS', 30),
        'fetch_timeout_seconds' => 20,
        'max_fetch_bytes' => 3_000_000,
        // Reference domains anyone may add besides each institution's own website.
        'reference_domains' => ['commonapp.org', 'ucas.com', 'collegeboard.org', 'act.org', 'studentaid.gov', 'ets.org', 'ielts.org', 'educationusa.state.gov'],
        // Local development only: extra hosts (e.g. a fixture server) and plain http. Never enable in production.
        'extra_hosts' => array_values(array_filter(explode(',', (string) env('ADMITME_KNOWLEDGE_EXTRA_HOSTS', '')))),
        'allow_http' => (bool) env('ADMITME_KNOWLEDGE_ALLOW_HTTP', false),
    ],

    'tasks' => [
        // Independent steps that may run at once for one student (plan: up to three research jobs).
        'max_running_per_student' => (int) env('ADMITME_TASKS_MAX_RUNNING', 3),
        // A worker's claim on a running step; must exceed the job timeout (600s). Expired = worker died.
        'lease_seconds' => (int) env('ADMITME_TASKS_LEASE_SECONDS', 900),
        // Delay before an automatic retry, multiplied by the attempt number.
        'retry_backoff_seconds' => (int) env('ADMITME_TASKS_RETRY_BACKOFF', 20),
        // Steps running at once across all students.
        'max_running_global' => (int) env('ADMITME_TASKS_MAX_RUNNING_GLOBAL', 20),
        // Model calls per provider per minute from task steps (all students); over it, steps wait.
        'provider_calls_per_minute' => (int) env('ADMITME_TASKS_PROVIDER_CALLS_PER_MINUTE', 30),
    ],

    'exports' => [
        // An export can be downloaded for this long; then its archive is deleted (admitme:prune-data).
        'expires_after_hours' => (int) env('ADMITME_EXPORT_EXPIRES_HOURS', 24),
    ],

    // Optional, for admitme:metrics cost estimates: USD per million tokens, copied from your provider's price
    // sheet, e.g. 'claude-haiku-5-5' => ['input_per_million' => 1.0, 'output_per_million' => 5.0] (example
    // numbers). Empty = costs are not estimated; token counts are always reported.
    'pricing' => [],

    'browser' => [
        // How long the extension holds a fill step before it may be handed out again (then it is reconciled:
        // the field is read first, so a step whose acknowledgement was lost is not typed twice).
        'command_lease_seconds' => (int) env('ADMITME_BROWSER_LEASE_SECONDS', 60),

        // Mentor browser control: how often a Browser tool checks whether the side panel reported its action back.
        'agent_poll_ms' => (int) env('ADMITME_BROWSER_AGENT_POLL_MS', 250),
    ],

    'retention' => [
        // Operational records of what each mentor run was given (not the conversation itself).
        'context_snapshot_days' => (int) env('ADMITME_CONTEXT_SNAPSHOT_DAYS', 90),
        // What the browser extension observed on a form (field labels and the values shown then).
        'browser_snapshot_days' => (int) env('ADMITME_BROWSER_SNAPSHOT_DAYS', 30),
    ],

    'writing' => [
        // Used when a college has no recorded policy: comments and questions only, never generated text.
        'default_policy' => env('ADMITME_WRITING_DEFAULT_POLICY', 'feedback_only'),
        'max_feedback_comments' => 12,
    ],

    'approvals' => [
        // Undecided proposals expire so an old suggestion cannot be approved against much newer data.
        'expires_after_days' => (int) env('ADMITME_PROPOSAL_EXPIRY_DAYS', 7),
    ],

    'summaries' => [
        'enabled' => (bool) env('ADMITME_SUMMARIES_ENABLED', true),
        'provider' => env('ADMITME_SUMMARY_PROVIDER'),
        'model' => env('ADMITME_SUMMARY_MODEL'),

        // Summarise once at least this many stored turns fall outside the replayed history.
        'min_unsummarized_turns' => 6,
    ],

];
