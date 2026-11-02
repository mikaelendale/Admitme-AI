<?php

namespace App\Ai;

use App\Ai\Agents\Subagents\MentorSubagent;
use InvalidArgumentException;

/**
 * Decides which provider and model each AdmitMe agent uses, from config/admitme.php:
 * per-agent override → dedicated mentor/summary settings → the mentor's own model for its provider →
 * the global provider and its default model.
 *
 * Keys live in config/ai.php; this only picks among configured providers.
 */
final class AiModelResolver
{
    public function providerFor(string $agentClass): string
    {
        return (string) ($this->override($agentClass)['provider'] ?? config('admitme.ai.provider', 'openai'));
    }

    public function modelFor(string $agentClass): string
    {
        $override = $this->override($agentClass);

        if (! empty($override['model'])) {
            return (string) $override['model'];
        }

        $provider = $this->providerFor($agentClass);
        $model = $this->dedicatedModel($agentClass, $provider) ?? config("admitme.ai.models.{$provider}");

        if (! is_string($model) || $model === '') {
            throw new InvalidArgumentException("No default model is configured for AI provider [{$provider}] (admitme.ai.models.{$provider}).");
        }

        return $model;
    }

    /**
     * The providers to try, in order, with the model to use on each: this agent's own provider and model first,
     * then every other provider that has an API key (admitme.ai.failover can turn this off).
     *
     * @return array<string, string>
     */
    public function failoverFor(string $agentClass): array
    {
        $primary = $this->providerFor($agentClass);
        $chain = [$primary => $this->modelFor($agentClass)];

        if (! config('admitme.ai.failover', true)) {
            return $chain;
        }

        foreach ((array) config('admitme.ai.failover_providers', ['anthropic', 'openai', 'gemini']) as $provider) {
            if ($provider === $primary || isset($chain[$provider]) || blank(config("ai.providers.{$provider}.key"))) {
                continue;
            }

            $model = $this->dedicatedModel($agentClass, $provider) ?? config("admitme.ai.models.{$provider}");

            if (is_string($model) && $model !== '') {
                $chain[$provider] = $model;
            }
        }

        return $chain;
    }

    /**
     * The mentor's own model for a provider (config admitme.mentor.models), when one is configured.
     */
    private function dedicatedModel(string $agentClass, string $provider): ?string
    {
        if (! $this->isMentor($agentClass)) {
            return null;
        }

        $model = config("admitme.mentor.models.{$provider}");

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * @return array{provider?: string, model?: string}
     */
    private function override(string $agentClass): array
    {
        $key = class_basename($agentClass);

        $dedicated = match (true) {
            $this->isMentor($agentClass) => ['provider' => config('admitme.mentor.provider'), 'model' => config('admitme.mentor.model')],
            $key === 'ConversationSummarizerAgent' => ['provider' => config('admitme.summaries.provider'), 'model' => config('admitme.summaries.model')],
            default => [],
        };

        $configured = (array) (config('admitme.ai.agents', [])[$key] ?? []);

        return array_filter([...$dedicated, ...$configured], fn (mixed $value) => is_string($value) && $value !== '');
    }

    /**
     * The mentor and the specialists it delegates to share the mentor's provider and model.
     */
    private function isMentor(string $agentClass): bool
    {
        return class_basename($agentClass) === 'AdmitMeGeneralAgent' || is_subclass_of($agentClass, MentorSubagent::class);
    }
}
