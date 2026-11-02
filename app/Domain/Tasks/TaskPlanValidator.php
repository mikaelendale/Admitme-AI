<?php

namespace App\Domain\Tasks;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Turns a proposed plan (from the mentor or a playbook) into validated steps. Laravel decides what runs:
 * only registered types, validated inputs, at most a handful of steps, and dependencies only on steps listed
 * earlier — so a plan is always acyclic and the model never executes arbitrary instructions.
 */
final class TaskPlanValidator
{
    public const MAX_STEPS = 6;

    public function __construct(private readonly TaskRegistry $registry) {}

    /**
     * @param  list<mixed>  $steps
     * @return list<array{key: string, type: string, title: string, input: array<string, mixed>, depends_on: list<string>}>
     *
     * @throws ValidationException
     */
    public function validate(string $goal, array $steps): array
    {
        Validator::make(['goal' => $goal, 'steps' => $steps], [
            'goal' => ['required', 'string', 'min:5', 'max:300'],
            'steps' => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            'steps.*' => ['array'],
            'steps.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,30}$/', 'distinct'],
            'steps.*.type' => ['required', 'string', Rule::in($this->registry->types())],
            'steps.*.title' => ['nullable', 'string', 'max:200'],
            'steps.*.input' => ['nullable', 'array'],
            'steps.*.depends_on' => ['nullable', 'array', 'max:'.self::MAX_STEPS],
            'steps.*.depends_on.*' => ['string'],
        ], [
            'steps.*.type.in' => 'Unknown step type. Allowed: '.implode(', ', $this->registry->types()).'.',
        ])->validate();

        $seen = [];
        $normalized = [];

        foreach (array_values($steps) as $index => $step) {
            $handler = $this->registry->handlerClass($step['type']);
            $input = array_intersect_key((array) ($step['input'] ?? []), $handler::rules());

            $validator = Validator::make($input, $handler::rules());

            if ($validator->fails()) {
                throw ValidationException::withMessages(collect($validator->errors()->toArray())
                    ->mapWithKeys(fn (array $messages, string $field) => ["steps.{$index}.input.{$field}" => $messages])
                    ->all());
            }

            $dependsOn = array_values(array_unique((array) ($step['depends_on'] ?? [])));

            foreach ($dependsOn as $dependency) {
                if (! in_array($dependency, $seen, true)) {
                    throw ValidationException::withMessages(["steps.{$index}.depends_on" => "Step \"{$step['key']}\" can only depend on steps listed before it; \"{$dependency}\" is not one."]);
                }
            }

            $validated = $validator->validated();

            $normalized[] = [
                'key' => $step['key'],
                'type' => $step['type'],
                'title' => trim((string) ($step['title'] ?? '')) !== '' ? trim((string) $step['title']) : $handler::defaultTitle($validated),
                'input' => $validated,
                'depends_on' => $dependsOn,
            ];

            $seen[] = $step['key'];
        }

        return $normalized;
    }
}
