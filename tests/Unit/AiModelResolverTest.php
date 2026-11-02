<?php

namespace Tests\Unit;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Agents\ConversationSummarizerAgent;
use App\Ai\Agents\CouncilJudgeAgent;
use App\Ai\Agents\EssayRoastAgent;
use App\Ai\AiModelResolver;
use App\Models\User;
use Tests\TestCase;

/**
 * Every AdmitMe agent's provider/model comes from config, so the app can run on whichever provider has a
 * working key (e.g. when the OpenAI key is revoked).
 */
class AiModelResolverTest extends TestCase
{
    public function test_defaults_keep_the_original_openai_model(): void
    {
        config(['admitme.ai.failover' => false, 'admitme.ai.provider' => 'openai', 'admitme.ai.models.openai' => 'gpt-5-nano', 'admitme.mentor.provider' => null, 'admitme.mentor.model' => null, 'admitme.mentor.models' => []]);

        $this->assertSame('openai', (new EssayRoastAgent)->provider());
        $this->assertSame('gpt-5-nano', (new EssayRoastAgent)->model());
        $this->assertSame('gpt-5-nano', (new AdmitMeGeneralAgent(new User))->model());
    }

    public function test_switching_the_global_provider_moves_every_agent(): void
    {
        config(['admitme.ai.failover' => false, 'admitme.ai.provider' => 'anthropic', 'admitme.ai.models.anthropic' => 'claude-haiku-5-5', 'admitme.mentor.provider' => null, 'admitme.mentor.model' => null, 'admitme.mentor.models' => []]);

        foreach ([new EssayRoastAgent, new CouncilJudgeAgent, new ConversationSummarizerAgent, new AdmitMeGeneralAgent(new User)] as $agent) {
            $this->assertSame('anthropic', $agent->provider(), $agent::class);
            $this->assertSame('claude-haiku-5-5', $agent->model(), $agent::class);
        }
    }

    public function test_per_agent_and_mentor_overrides_win(): void
    {
        config([
            'admitme.ai.provider' => 'openai',
            'admitme.ai.models.anthropic' => 'claude-haiku-5-5',
            'admitme.ai.agents' => ['CouncilJudgeAgent' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']],
            'admitme.mentor.provider' => 'anthropic',
            'admitme.mentor.model' => null,
            'admitme.mentor.models' => [],
        ]);

        $resolver = app(AiModelResolver::class);

        $this->assertSame('claude-sonnet-5-5', $resolver->modelFor(CouncilJudgeAgent::class));
        $this->assertSame('anthropic', $resolver->providerFor(AdmitMeGeneralAgent::class));
        $this->assertSame('claude-haiku-5-5', $resolver->modelFor(AdmitMeGeneralAgent::class), 'Mentor uses its provider\'s default model when no model is set.');
        $this->assertSame('openai', $resolver->providerFor(EssayRoastAgent::class));
    }

    public function test_the_mentor_gets_its_own_stronger_model_per_provider(): void
    {
        config([
            'admitme.ai.provider' => 'openai',
            'admitme.ai.models' => ['openai' => 'gpt-5-nano', 'anthropic' => 'claude-haiku-5-5'],
            'admitme.mentor.provider' => null,
            'admitme.mentor.model' => null,
            'admitme.mentor.models' => ['openai' => 'gpt-5.5', 'anthropic' => 'claude-sonnet-5-5'],
        ]);

        $resolver = app(AiModelResolver::class);

        $this->assertSame('gpt-5.5', $resolver->modelFor(AdmitMeGeneralAgent::class));
        $this->assertSame('gpt-5-nano', $resolver->modelFor(EssayRoastAgent::class), 'Background agents keep the global default.');

        config(['admitme.mentor.provider' => 'anthropic']);
        $this->assertSame('claude-sonnet-5-5', $resolver->modelFor(AdmitMeGeneralAgent::class));

        config(['admitme.mentor.model' => 'claude-opus-5-5']);
        $this->assertSame('claude-opus-5-5', $resolver->modelFor(AdmitMeGeneralAgent::class), 'An explicit mentor model wins.');
    }

    public function test_an_agent_fails_over_to_every_other_provider_that_has_a_key(): void
    {
        config([
            'admitme.ai.failover' => true,
            'admitme.ai.provider' => 'anthropic',
            'admitme.ai.models' => ['anthropic' => 'claude-haiku-5-5', 'openai' => 'gpt-5-nano', 'gemini' => 'gemini-3.7-flash'],
            'admitme.mentor.provider' => 'anthropic',
            'admitme.mentor.model' => null,
            'admitme.mentor.models' => ['anthropic' => 'claude-sonnet-5-5', 'openai' => 'gpt-5.5'],
            'ai.providers.anthropic.key' => 'test-anthropic',
            'ai.providers.openai.key' => 'test-openai',
            'ai.providers.gemini.key' => null,
        ]);

        $resolver = app(AiModelResolver::class);

        // The mentor keeps its stronger model on the fallback provider; no key, no fallback.
        $this->assertSame(['anthropic' => 'claude-sonnet-5-5', 'openai' => 'gpt-5.5'], $resolver->failoverFor(AdmitMeGeneralAgent::class));
        $this->assertSame(['anthropic' => 'claude-haiku-5-5', 'openai' => 'gpt-5-nano'], $resolver->failoverFor(EssayRoastAgent::class));

        // The SDK receives the ordered list; the primary provider is still reported by name.
        $this->assertSame(['anthropic' => 'claude-sonnet-5-5', 'openai' => 'gpt-5.5'], (new AdmitMeGeneralAgent(new User))->provider());
        $this->assertSame('anthropic', $resolver->providerFor(AdmitMeGeneralAgent::class));

        // With a single provider configured, or failover off, it stays a plain provider name.
        config(['ai.providers.openai.key' => null]);
        $this->assertSame('anthropic', (new EssayRoastAgent)->provider());

        config(['ai.providers.openai.key' => 'test-openai', 'admitme.ai.failover' => false]);
        $this->assertSame('anthropic', (new EssayRoastAgent)->provider());
    }
}
