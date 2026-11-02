<?php

namespace App\Domain\Audit;

use App\Domain\Shared\ChangeSource;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    /**
     * Record a change to a student's data.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  Field => [before, after].
     * @param  array<string, mixed>  $context
     */
    public function record(
        int $studentId,
        ChangeSource $source,
        string $action,
        Model $subject,
        ?int $revisionBefore,
        ?int $revisionAfter,
        array $changes,
        array $context = [],
    ): AuditEvent {
        return AuditEvent::query()->create([
            'user_id' => $studentId,
            'actor_type' => $source->actorType,
            'actor_id' => $source->actorId,
            'action' => $action,
            'subject_type' => class_basename($subject),
            'subject_id' => (string) $subject->getKey(),
            'revision_before' => $revisionBefore,
            'revision_after' => $revisionAfter,
            'changes' => array_map(fn (array $pair) => ['before' => $pair[0], 'after' => $pair[1]], $changes),
            'context' => [...$source->toArray(), ...$context],
        ]);
    }
}
