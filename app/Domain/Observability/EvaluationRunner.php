<?php

namespace App\Domain\Observability;

use App\Ai\Agents\RequirementExtractorAgent;
use App\Ai\Agents\WritingCoachAgent;
use App\Ai\AiModelResolver;
use App\Domain\Knowledge\RequirementExtractionService;
use App\Domain\Writing\WritingCoach;
use App\Domain\Writing\WritingPolicy;
use App\Domain\Writing\WritingService;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * A small, replayable evaluation set (resources/evals/*.json, synthetic) run through the production prompts
 * and validators — requirement extraction and writing feedback — without writing anything to the database.
 * Results name the provider, model and code version, so prompt/model/tool changes can be compared before release.
 */
final class EvaluationRunner
{
    public const SUITES = ['research', 'writing'];

    public function __construct(
        private readonly RequirementExtractionService $extraction,
        private readonly WritingCoach $coach,
        private readonly AiModelResolver $models,
    ) {}

    /**
     * @return array{suite: string, run_at: string, provider: string, model: string, instruction_version: string|null, cases: list<array<string, mixed>>, summary: array<string, int|float>}
     */
    public function run(string $suite): array
    {
        if (! in_array($suite, self::SUITES, true)) {
            throw new InvalidArgumentException("Unknown evaluation suite [{$suite}].");
        }

        $definition = json_decode((string) file_get_contents(resource_path("evals/{$suite}.json")), true);
        $agent = $suite === 'research' ? RequirementExtractorAgent::class : WritingCoachAgent::class;
        $cases = [];

        foreach ($definition['cases'] as $case) {
            $started = hrtime(true);

            try {
                $result = $suite === 'research' ? $this->research($case) : $this->writing($case);
            } catch (Throwable $e) {
                $result = ['passed' => false, 'metrics' => [], 'notes' => ['error: '.RunInstrumentation::redact(class_basename($e).': '.mb_substr($e->getMessage(), 0, 300))]];
            }

            $cases[] = ['id' => (string) $case['id'], ...$result, 'duration_ms' => RunInstrumentation::elapsedMs($started)];
        }

        return [
            'suite' => $suite,
            'run_at' => now()->toIso8601String(),
            'provider' => $this->models->providerFor($agent),
            'model' => $this->models->modelFor($agent),
            'instruction_version' => RunInstrumentation::codeVersion($agent),
            'cases' => $cases,
            'summary' => $this->summary($cases),
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{passed: bool, metrics: array<string, int|float|bool>, notes: list<string>}
     */
    private function research(array $case): array
    {
        $response = $this->extraction->ask($case['title'] ?? null, (string) ($case['url'] ?? 'https://example.invalid/'), (string) $case['page']);
        $checked = $this->extraction->validate((string) $case['page'], (array) ($response['requirements'] ?? []));
        $notes = [];
        $found = 0;
        $forbidden = 0;

        foreach ((array) $case['expect'] as $expectation) {
            if ($this->matches($checked['kept'], $expectation)) {
                $found++;
            } else {
                $notes[] = 'missing: '.json_encode($expectation);
            }
        }

        foreach ((array) $case['forbid'] as $rule) {
            if ($this->matches($checked['kept'], $rule)) {
                $forbidden++;
                $notes[] = 'kept something forbidden: '.json_encode($rule);
            }
        }

        return [
            'passed' => $found === count((array) $case['expect']) && $forbidden === 0,
            'metrics' => ['kept' => count($checked['kept']), 'dropped' => $checked['dropped'], 'expected' => count((array) $case['expect']), 'found' => $found, 'forbidden_kept' => $forbidden],
            'notes' => $notes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $kept
     * @param  array<string, mixed>  $rule
     */
    private function matches(array $kept, array $rule): bool
    {
        foreach ($kept as $requirement) {
            $text = mb_strtolower(implode(' ', [$requirement['label'], $requirement['value_text'] ?? '', $requirement['conditions'] ?? '', $requirement['quote']]));

            $ok = $requirement['requirement_type'] === $rule['type']
                && (! isset($rule['date']) || $requirement['deadline_date'] === $rule['date'])
                && (! isset($rule['amount']) || ($requirement['amount'] !== null && abs((float) $requirement['amount'] - (float) $rule['amount']) < 0.001))
                && (! isset($rule['has_date']) || ($requirement['deadline_date'] !== null) === (bool) $rule['has_date'])
                && (! isset($rule['contains']) || str_contains($text, mb_strtolower((string) $rule['contains'])));

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{passed: bool, metrics: array<string, int|float|bool>, notes: list<string>}
     */
    private function writing(array $case): array
    {
        $policy = WritingPolicy::from((string) $case['policy']);
        $expect = (array) $case['expect'];

        try {
            $review = $this->coach->review((string) $case['draft'], $policy, $case['prompt'] ?? null, $case['word_limit'] ?? null, WritingService::wordCount((string) $case['draft']), 1);
        } catch (ValidationException) {
            $expected = (bool) ($expect['refused'] ?? false);

            return ['passed' => $expected, 'metrics' => ['refused' => true], 'notes' => $expected ? [] : ['refused, but feedback was expected']];
        }

        $comments = count($review['comments']);
        $dropped = $review['dropped'];
        $dropRate = $comments + $dropped > 0 ? round($dropped / ($comments + $dropped), 3) : 0.0;
        $fixes = count(array_filter($review['comments'], fn (array $comment) => $comment['fix'] !== null));
        $policyRespected = $policy->allowsLanguageFixes() || $fixes === 0;
        $notes = array_values(array_filter([
            ($expect['refused'] ?? false) ? 'expected a refusal (the model must not be called)' : null,
            $comments < (int) ($expect['min_comments'] ?? 1) ? "only {$comments} comment(s) survived" : null,
            $dropRate > (float) ($expect['max_drop_rate'] ?? 1) ? "{$dropped} comment(s) dropped for quoting words not in the draft" : null,
            $policyRespected ? null : 'replacement text survived under a policy that forbids it',
        ]));

        return [
            'passed' => $notes === [],
            'metrics' => ['comments' => $comments, 'dropped' => $dropped, 'drop_rate' => $dropRate, 'fixes' => $fixes, 'policy_respected' => $policyRespected],
            'notes' => $notes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, int|float>
     */
    private function summary(array $cases): array
    {
        $sum = fn (string $metric) => array_sum(array_map(fn (array $c) => (float) ($c['metrics'][$metric] ?? 0), $cases));
        $kept = $sum('kept') + $sum('comments');
        $dropped = $sum('dropped');

        return [
            'cases' => count($cases),
            'passed' => count(array_filter($cases, fn (array $c) => $c['passed'])),
            // Research: expected requirements found, and forbidden ones (e.g. injected values) kept.
            'expected' => (int) $sum('expected'),
            'found' => (int) $sum('found'),
            'forbidden_kept' => (int) $sum('forbidden_kept'),
            // Both: items that passed the verbatim-quote check, and items dropped for failing it.
            'kept' => (int) $kept,
            'dropped' => (int) $dropped,
            'quote_drop_rate' => $kept + $dropped > 0 ? round($dropped / ($kept + $dropped), 3) : 0.0,
        ];
    }
}
