<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use App\Ai\Context\InstructionLoader;
use App\Domain\Documents\CandidateExtractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Lists candidate profile values found in one document, each with a page and verbatim quote. No tools.
 */
#[Timeout(180)]
class DocumentFactExtractorAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return app(InstructionLoader::class)->load('agents/document-extractor.md');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'candidates' => $schema->array()
                ->items($schema->object([
                    'field' => $schema->string()->enum(CandidateExtractionService::EXTRACTABLE_FIELDS)->required(),
                    'value' => $schema->string()->description('The value exactly as written (numbers as digits).')->required(),
                    'page' => $schema->integer()->min(1)->required(),
                    'quote' => $schema->string()->description('Verbatim text copied from that page containing the value.')->required(),
                    'ambiguous' => $schema->boolean()->required(),
                    'note' => $schema->string()->description('Why it is uncertain, or null.')->nullable()->required(),
                ])->withoutAdditionalProperties())
                ->max(20)
                ->required(),
        ];
    }
}
