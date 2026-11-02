<?php

namespace App\Ai\Concerns;

use App\Ai\AiModelResolver;

/**
 * Lets an agent's provider and model come from config/admitme.php instead of hard-coded attributes.
 * The SDK prefers these methods over #[Provider]/#[Model] attributes.
 *
 * When another provider with a working key is configured, provider() returns an ordered provider => model
 * list: the SDK tries the first and fails over to the next when a provider cannot answer before it has
 * produced anything (out of credits, overloaded, rate limited, unreachable).
 */
trait UsesConfiguredModel
{
    /**
     * @return string|array<string, string>
     */
    public function provider(): string|array
    {
        $resolver = app(AiModelResolver::class);
        $chain = $resolver->failoverFor(static::class);

        return count($chain) > 1 ? $chain : $resolver->providerFor(static::class);
    }

    public function model(): string
    {
        return app(AiModelResolver::class)->modelFor(static::class);
    }
}
