<?php

namespace App\Ai\Tools;

use App\Models\JobTracking;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets the mentor agent check background job status for the current user.
 */
class GetActiveJobsTool implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Check the status of background jobs running for this student (college list generation, essay runs, profile rebuilds, etc.).

Use this tool when the student asks questions like:
- "Is my college list ready?"
- "How long has the essay been running?"
- "Why hasn't my list been built yet?"
- "Did anything fail?"

Returns active (pending/running) jobs and any jobs that failed in the last 24 hours, with retry URLs for failed ones.

Do NOT use this tool unprompted — only when the student asks about job status.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $includeRetryUrls = $request->boolean('include_failed_retry_urls', true);

        try {
            $active = JobTracking::query()
                ->where('user_id', $this->user->id)
                ->whereIn('status', [JobTracking::STATUS_PENDING, JobTracking::STATUS_RUNNING])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (JobTracking $t) => [
                    'type' => $t->job_type,
                    'label' => $t->label(),
                    'status' => $t->status,
                    'started_ago' => $t->startedAgo(),
                ]);

            $recentFailed = JobTracking::query()
                ->where('user_id', $this->user->id)
                ->where('status', JobTracking::STATUS_FAILED)
                ->where('failed_at', '>=', now()->subHours(24))
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get()
                ->map(function (JobTracking $t) use ($includeRetryUrls) {
                    $row = [
                        'type' => $t->job_type,
                        'label' => $t->label(),
                        'status' => 'failed',
                    ];
                    if ($includeRetryUrls) {
                        $row['retry_url'] = $t->retryUrl();
                    }

                    return $row;
                });

            return json_encode([
                'ok' => true,
                'active_jobs' => $active,
                'recently_failed_jobs' => $recentFailed,
                'active_count' => $active->count(),
                'failed_count' => $recentFailed->count(),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => 'Could not retrieve job status: '.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * OpenAI requires a non-empty `properties` object with `additionalProperties: false`
     * on tool parameters — an empty schema is rejected (400 invalid_function_parameters).
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_failed_retry_urls' => $schema->boolean()
                ->description('Whether to include retry URLs for jobs that failed in the last 24 hours. Defaults to true.')
                ->default(true)
                ->required(),
        ];
    }
}
