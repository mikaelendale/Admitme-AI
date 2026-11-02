<?php

namespace App\Domain\Students;

use App\Domain\Shared\ChangeSource;
use App\Models\StudentFact;

/**
 * Writes provenance rows for values that were just stored on the profile, superseding the previous ones.
 */
final class StudentFactRecorder
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  Field key => [before, after].
     * @param  array<string, mixed>|null  $sourceRef  Evidence reference (route, document page, proposal id …).
     */
    public function record(int $studentId, array $changes, ChangeSource $source, int $profileRevision, ?array $sourceRef = null): void
    {
        $status = $this->statusFor($source);
        $now = now();

        foreach ($changes as $key => [, $after]) {
            StudentFact::query()
                ->where('user_id', $studentId)
                ->where('field_key', $key)
                ->current()
                ->update(['superseded_at' => $now]);

            StudentFact::query()->create([
                'user_id' => $studentId,
                'field_key' => $key,
                'value' => $after,
                'status' => $status,
                'source_type' => $source->sourceType,
                'source_ref' => $sourceRef ?? ($source->context !== [] ? $source->context : null),
                'actor_type' => $source->actorType,
                'profile_revision' => $profileRevision,
            ]);
        }
    }

    private function statusFor(ChangeSource $source): FactStatus
    {
        if ($source->isStudentConfirmed()) {
            return FactStatus::Confirmed;
        }

        // An older screen the student filled in themselves still counts as their own answer...
        if ($source->sourceType === ChangeSource::SOURCE_LEGACY_FLOW && $source->actorType === ChangeSource::ACTOR_STUDENT) {
            return FactStatus::Confirmed;
        }

        return FactStatus::Inferred;
    }
}
