<?php

namespace App\Domain\Tasks;

use App\Domain\Tasks\Handlers\AffordabilityCheckHandler;
use App\Domain\Tasks\Handlers\ApplicationPlanHandler;
use App\Domain\Tasks\Handlers\CollegeResearchHandler;
use App\Domain\Tasks\Handlers\RefreshSourcesHandler;
use App\Domain\Tasks\Handlers\ShortlistProposalHandler;
use InvalidArgumentException;

/**
 * The only step types a plan may contain.
 */
final class TaskRegistry
{
    /**
     * @var list<class-string<TaskHandler>>
     */
    private const HANDLERS = [
        CollegeResearchHandler::class,
        AffordabilityCheckHandler::class,
        ShortlistProposalHandler::class,
        RefreshSourcesHandler::class,
        ApplicationPlanHandler::class,
    ];

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(fn (string $handler) => $handler::type(), self::HANDLERS);
    }

    /**
     * @return class-string<TaskHandler>
     */
    public function handlerClass(string $type): string
    {
        foreach (self::HANDLERS as $handler) {
            if ($handler::type() === $type) {
                return $handler;
            }
        }

        throw new InvalidArgumentException("Unknown task type [{$type}].");
    }

    public function handler(string $type): TaskHandler
    {
        return app($this->handlerClass($type));
    }

    /**
     * For the planner's tool description.
     */
    public function describeAll(): string
    {
        return implode("\n", array_map(fn (string $handler) => '- '.$handler::type().': '.$handler::describe(), self::HANDLERS));
    }
}
