<?php

namespace App\Domain\Tasks;

use App\Models\AgentTask;

/**
 * One kind of step a plan may contain. Plans can only name registered types; inputs are validated with
 * rules(); the handler is server code that decides what the step may touch (workers get no free tools).
 */
interface TaskHandler
{
    /** Stable key used in plans. */
    public static function type(): string;

    /** One line for the planner: what the step does and which inputs it takes. */
    public static function describe(): string;

    /**
     * Validation rules for the step's input (keys relative to the input object).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array;

    /**
     * @return array{max_model_calls: int, max_attempts: int}
     */
    public static function budget(): array;

    /**
     * @param  array<string, mixed>  $input
     */
    public static function defaultTitle(array $input): string;

    public function handle(AgentTask $task, TaskContext $context): TaskOutcome;
}
