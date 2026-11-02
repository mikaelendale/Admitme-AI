<?php

namespace App\Ai\Agents\Subagents;

use App\Ai\Concerns\UsesConfiguredModel;
use App\Ai\Context\InstructionLoader;
use App\Ai\Tools\Contracts\ReadOnlyTool;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A specialist the mentor delegates one self-contained task to (the SDK runs it as a sub-agent tool and
 * streams its output into the chat). A sub-agent sees only the task text — never the conversation or the
 * student's records — and changes nothing, so it is offered in Ask mode too. It runs on the mentor's model.
 */
#[Timeout(240)]
abstract class MentorSubagent implements Agent, CanActAsTool, ReadOnlyTool
{
    use Promptable, UsesConfiguredModel;

    /**
     * The instruction file under resources/ai for this specialist.
     */
    abstract protected function instructionFile(): string;

    public function instructions(): Stringable|string
    {
        $loader = app(InstructionLoader::class);

        return $loader->load('AGENTS.md')."\n\n".$loader->load($this->instructionFile());
    }
}
