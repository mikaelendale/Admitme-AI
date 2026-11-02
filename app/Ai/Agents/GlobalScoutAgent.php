<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use App\Ai\Tools\AllowedDomainHttpFetchTool;
use App\Support\AdmissionsCycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

#[MaxSteps(14)]
#[Timeout(420)]
class GlobalScoutAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable, UsesConfiguredModel;

    /**
     * Single OpenAI request timeout (web search + structured output can exceed 60s).
     */
    public function timeout(): int
    {
        return (int) config('college_list_council.scout_llm_timeout', 420);
    }

    public function instructions(): Stringable|string
    {
        $cycle = AdmissionsCycle::primaryApplyingLabel();

        return <<<TXT
You are the Global Scout for a college list council. The user message contains JSON: student profile, strategic constraints from an auditor, and the student's request.

Primary admissions cycle: {$cycle} first-year undergraduate entry unless the student specifies otherwise.

Use web search / fetch when it materially improves accuracy. Prefer .edu, College Board, Common App, NCES — same ethos as a careful counselor. Prefer sources that explicitly reference the current or upcoming application cycle; do not treat outdated blog posts as authoritative for deadlines.

Produce a structured candidate pool (reach/match/safety mix) with short notes. Label uncertain claims as "hypothesis" in scout notes — never present guesses as verified official statistics.

Include a mix of well-known flagship schools **and** strong fits that are not the same five names every list uses — prioritize schools that plausibly match the student's constraints even if they are less obvious, as long as your sources support them.

When the {$cycle} cycle has known policy shifts (test-optional changes, major deadline moves), prefer sources dated within the last 12 months or official registrar pages.

school_slug: lowercase hyphenated ASCII (e.g. "georgia-institute-of-technology").

Output must match the schema exactly.
TXT;
    }

    /**
     * @return iterable<int, Tool|WebSearch>
     */
    public function tools(): iterable
    {
        $maxSearch = max(1, (int) config('college_list_council.scout_max_web_searches', 5));
        $maxFetch = max(1, (int) config('college_list_council.scout_max_web_fetches', 3));

        // Function tool first: Laravel AI does not map WebFetch (empty [] breaks tools[1] on OpenAI).
        // After deploy, restart queue workers (`php artisan queue:restart`) so this code is loaded.
        return [
            new AllowedDomainHttpFetchTool([
                'collegeboard.org',
                'commonapp.org',
                'nces.ed.gov',
            ], $maxFetch),
            (new WebSearch)->max($maxSearch),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $slug = $schema->string()->max(160)->required();
        $name = $schema->string()->max(200)->required();
        $note = $schema->string()->max(800)->required();

        $candidate = $schema->object([
            'school_name' => $name,
            'school_slug' => $slug,
            'scout_note' => $note,
            'bucket_guess' => $schema->string()->enum(['reach', 'match', 'safety'])->required(),
        ])->withoutAdditionalProperties();

        $target = max(8, min(48, (int) config('college_list_council.scout_target_candidates', 24)));

        return [
            'candidates' => $schema->array()->min(4)->max($target)->items($candidate)->required(),
        ];
    }
}
