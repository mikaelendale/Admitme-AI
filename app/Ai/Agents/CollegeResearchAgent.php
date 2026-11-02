<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use App\Ai\Context\InstructionLoader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Suggests candidate colleges for one student (a task step). No tools: the step gives it a server-built
 * brief for the authorized student, and its output only ever becomes reviewable suggestions.
 */
#[Timeout(180)]
class CollegeResearchAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return app(InstructionLoader::class)->load('agents/college-researcher.md');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'candidates' => $schema->array()
                ->items($schema->object([
                    'school_name' => $schema->string()->max(200)->required(),
                    'tier' => $schema->string()->enum(['reach', 'match', 'safety'])->required(),
                    'fit_score' => $schema->integer()->min(0)->max(100)->description('Your estimate of overall fit for this student.')->required(),
                    'why' => $schema->string()->max(600)->required(),
                    'notable_programs' => $schema->array()->items($schema->string()->max(120))->max(6)->required(),
                    'country' => $schema->string()->max(120)->nullable()->required(),
                    'location_state' => $schema->string()->max(2)->nullable()->required(),
                    'aid_estimate_band' => $schema->string()->enum(['strong', 'moderate', 'limited'])->nullable()->required(),
                    'median_gpa' => $schema->number()->min(0)->max(4)->nullable()->required(),
                ])->withoutAdditionalProperties())
                ->max(10)
                ->required(),
        ];
    }
}
