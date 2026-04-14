<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Support\FeatureQuotaManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets the mentor agent read the student's plan limits and current usage (quotas).
 */
class GetFeatureQuotaTool implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Read the student's current plan and feature usage limits (gap analysis, essay roast, mentor chat messages, college list generations, etc.).

Use this when the student asks how many uses they have left, whether they can run another analysis, or before starting expensive actions if they are close to a limit.

Returns a structured summary including plan name, per-feature used/limit/remaining, and whether they are in the "warning" zone (typically 80%+ used).

Do NOT call this on every turn — only when limits, upgrades, or "can I still…" questions are relevant.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        unset($request);

        try {
            $manager = app(FeatureQuotaManager::class);
            $summary = $manager->usageSummaryForInertia($this->user);

            return json_encode([
                'ok' => true,
                'quotas' => $summary,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => 'Could not load quota information: '.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * OpenAI requires at least one property with `additionalProperties: false`.
     * An empty schema causes a 400 invalid_function_parameters error.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_chat_detail' => $schema->boolean()
                ->description('Include chat-specific quota fields. Defaults to true; always returns the full summary regardless.')
                ->default(true)
                ->required(),
        ];
    }
}
