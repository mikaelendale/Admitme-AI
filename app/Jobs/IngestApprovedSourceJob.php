<?php

namespace App\Jobs;

use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Knowledge\KnowledgeIngestionService;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;

/**
 * Reads a source page the student approved adding (from a mentor proposal). It runs after the decision is
 * saved, so a slow page never holds the decision's database locks. The result shows where sources do: a page
 * that cannot be read is kept as a failed source with its reason; requirement extraction is queued on success.
 */
class IngestApprovedSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $studentId,
        public readonly string $collegeListSchoolId,
        public readonly string $url,
        public readonly bool $official,
    ) {}

    public function handle(InstitutionResolver $institutions, KnowledgeIngestionService $ingestion): void
    {
        $student = User::query()->find($this->studentId);
        $school = CollegeListSchool::query()->where('user_id', $this->studentId)->find($this->collegeListSchoolId);

        if ($student === null || $school === null) {
            return;
        }

        try {
            $ingestion->ingest($institutions->forSchool($school), $this->url, $student, $this->official);
        } catch (ValidationException) {
            // The link was allowed when approved; if the policy refuses it now (e.g. the host moved to a private
            // address), nothing is fetched or stored.
        }
    }
}
