<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use App\Ai\Context\InstructionLoader;
use App\Models\AdmissionRequirement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Lists the admissions requirements a page states, each with a verbatim quote. No tools.
 */
#[Timeout(180)]
class RequirementExtractorAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return app(InstructionLoader::class)->load('agents/requirements-extractor.md');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'requirements' => $schema->array()
                ->items($schema->object([
                    'requirement_type' => $schema->string()->enum(AdmissionRequirement::TYPES)->required(),
                    'label' => $schema->string()->description('Short name, e.g. "Regular Decision deadline".')->required(),
                    'value_text' => $schema->string()->description('The requirement as stated, or null.')->nullable()->required(),
                    'deadline_date' => $schema->string()->description('YYYY-MM-DD, or null.')->nullable()->required(),
                    'deadline_time' => $schema->string()->description('HH:MM 24h, or null.')->nullable()->required(),
                    'timezone' => $schema->string()->description('IANA timezone if stated, or null.')->nullable()->required(),
                    'amount' => $schema->number()->nullable()->required(),
                    'currency' => $schema->string()->description('ISO 4217 code if stated, or null.')->nullable()->required(),
                    'conditions' => $schema->string()->nullable()->required(),
                    'applicant_scope' => $schema->string()->enum(['all', 'international', 'domestic'])->required(),
                    'cycle_label' => $schema->string()->description('Intake/entry term stated on the page, or null.')->nullable()->required(),
                    'program' => $schema->string()->description('Programme it applies to, or null for all.')->nullable()->required(),
                    'quote' => $schema->string()->description('Verbatim text from the page.')->required(),
                ])->withoutAdditionalProperties())
                ->max(40)
                ->required(),
        ];
    }
}
