<?php

namespace App\Domain\Shared;

use App\Models\User;

/**
 * Who (or what) is changing student-owned data, and through which path.
 *
 * Every domain service write takes one of these so provenance and audit records say where a value came from.
 */
final readonly class ChangeSource
{
    public const ACTOR_STUDENT = 'student';

    public const ACTOR_AGENT = 'agent';

    public const ACTOR_SYSTEM = 'system';

    /** The student typed or picked the value themselves. */
    public const SOURCE_MANUAL = 'manual';

    /** An AI agent wrote directly through a legacy tool (pre-approval flows). */
    public const SOURCE_AGENT_TOOL = 'agent_tool';

    /** An agent proposal the student approved. */
    public const SOURCE_AGENT_PROPOSAL = 'agent_proposal';

    /** Values an AI extractor wrote from pasted text or a parsed file, not confirmed by the student. */
    public const SOURCE_AI_EXTRACTION = 'ai_extraction';

    /** A value read from one of the student's documents (a candidate until the student confirms it). */
    public const SOURCE_DOCUMENT = 'document';

    /** An older code path that saves the model directly instead of using a domain service. */
    public const SOURCE_LEGACY_FLOW = 'legacy_flow';

    public const SOURCE_SYSTEM = 'system';

    /**
     * @param  array<string, mixed>  $context  Non-sensitive details such as the route or tool name.
     */
    public function __construct(
        public string $actorType,
        public ?string $actorId,
        public string $sourceType,
        public array $context = [],
    ) {}

    public static function studentManual(User $student, string $via): self
    {
        return new self(self::ACTOR_STUDENT, (string) $student->getKey(), self::SOURCE_MANUAL, ['via' => $via]);
    }

    public static function agentTool(string $agent, string $tool): self
    {
        return new self(self::ACTOR_AGENT, $agent, self::SOURCE_AGENT_TOOL, ['tool' => $tool]);
    }

    public static function system(string $via): self
    {
        return new self(self::ACTOR_SYSTEM, null, self::SOURCE_SYSTEM, ['via' => $via]);
    }

    /**
     * Whether the value should count as confirmed by the student.
     */
    public function isStudentConfirmed(): bool
    {
        if (in_array($this->sourceType, [self::SOURCE_MANUAL, self::SOURCE_AGENT_PROPOSAL], true)) {
            return true;
        }

        // A document value only becomes a confirmed fact when the student accepts it in review...
        return $this->sourceType === self::SOURCE_DOCUMENT && $this->actorType === self::ACTOR_STUDENT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'source_type' => $this->sourceType,
            ...$this->context,
        ];
    }
}
