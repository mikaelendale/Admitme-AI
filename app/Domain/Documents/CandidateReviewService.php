<?php

namespace App\Domain\Documents;

use App\Domain\Approvals\ProposalConflictException;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Domain\Students\StudentFactRecorder;
use App\Domain\Students\StudentFieldRegistry;
use App\Domain\Students\StudentProfileService;
use App\Models\Document;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The student's review of values read from their documents. Accepting goes through the same profile
 * service as a manual edit, so the value becomes a confirmed fact citing the document page.
 */
final class CandidateReviewService
{
    public function __construct(
        private readonly StudentProfileService $profiles,
        private readonly StudentFactRecorder $facts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  'accept'|'reject'  $action
     * @param  mixed  $editedValue  The value the student typed instead, or null to accept as extracted.
     *
     * @throws ProposalConflictException when the candidate was already reviewed or its file was replaced.
     * @throws ValidationException
     */
    public function decide(User $student, StudentFact $candidate, string $action, mixed $editedValue, ?int $expectedRevision): StudentFact
    {
        return DB::transaction(function () use ($student, $candidate, $action, $editedValue, $expectedRevision) {
            /** @var StudentFact $locked */
            $locked = StudentFact::query()->lockForUpdate()->findOrFail($candidate->getKey());

            if ((int) $locked->user_id !== (int) $student->getKey() || ! in_array($locked->status, [FactStatus::Candidate, FactStatus::Conflict], true)) {
                throw new ProposalConflictException('This value is not awaiting review.');
            }

            if ($locked->review_state !== StudentFact::REVIEW_PENDING) {
                throw new ProposalConflictException("This value was already {$locked->review_state}.");
            }

            $document = Document::query()->find($locked->source_ref['document_id'] ?? null);
            if ($document === null || $document->current_version_id !== ($locked->source_ref['document_version_id'] ?? null)) {
                throw new ProposalConflictException('This value came from a version of the document you have since replaced.');
            }

            if ($action === 'reject') {
                $locked->update(['review_state' => StudentFact::REVIEW_REJECTED, 'superseded_at' => now()]);
                $this->audit->record((int) $student->getKey(), ChangeSource::studentManual($student, 'documents.review'), 'document_value.rejected', $locked, null, null, [
                    $locked->field_key => [$locked->value, null],
                ], ['document_id' => $document->getKey()]);

                return $locked;
            }

            $value = $editedValue ?? $locked->value;
            $source = new ChangeSource(ChangeSource::ACTOR_STUDENT, (string) $student->getKey(), ChangeSource::SOURCE_DOCUMENT, [
                'document_id' => $document->getKey(),
                'document_version_id' => $locked->source_ref['document_version_id'] ?? null,
                'page' => $locked->source_ref['page'] ?? null,
                'quote' => $locked->source_ref['quote'] ?? null,
                'candidate_id' => $locked->getKey(),
                'edited' => $editedValue !== null,
            ]);

            $result = $this->profiles->update($student, [$locked->field_key => $value], $expectedRevision, $source);

            // Same value as before: still record that the student confirmed it from this document...
            if (! $result->changed()) {
                $this->facts->record((int) $student->getKey(), [
                    $locked->field_key => [$result->profile->getAttribute(StudentFieldRegistry::column($locked->field_key)), $result->profile->getAttribute(StudentFieldRegistry::column($locked->field_key))],
                ], $source, $result->revisionAfter);
            }

            $locked->update(['review_state' => StudentFact::REVIEW_ACCEPTED, 'superseded_at' => now()]);

            return $locked;
        });
    }
}
