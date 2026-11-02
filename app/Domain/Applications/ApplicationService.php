<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\ChangeSource;
use App\Domain\Shared\StaleRevisionException;
use App\Models\Application;
use App\Models\CollegeListSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Shared write path for applications, used by the workspace and (later) approved agent proposals.
 */
final class ApplicationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApplicationChecklistService $checklist,
    ) {}

    /**
     * Open the application for a college choice, or return the one that already exists.
     */
    public function openFor(CollegeListSchool $school, ChangeSource $source): Application
    {
        return DB::transaction(function () use ($school, $source) {
            $existing = Application::query()
                ->where('user_id', $school->user_id)
                ->where('college_list_school_id', $school->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $application = Application::query()->create([
                'user_id' => $school->user_id,
                'college_list_school_id' => $school->getKey(),
                'status' => ApplicationStatus::Preparing,
                'portal_url' => $school->application_portal_url,
                'cycle_label' => $school->admissions_cycle_label,
            ]);

            $this->audit->record((int) $school->user_id, $source, 'application.opened', $application, null, 1, [
                'status' => [null, ApplicationStatus::Preparing->value],
            ], ['college' => $school->school_name]);

            // Start the workspace with the requirements already on file (and the default steps)...
            $this->checklist->build($application->setRelation('collegeListSchool', $school), $source);

            return $application->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws ValidationException
     * @throws StaleRevisionException
     */
    public function update(Application $application, array $changes, ?int $expectedRevision, ChangeSource $source): Application
    {
        $validated = Validator::make($changes, [
            'status' => ['sometimes', new Enum(ApplicationStatus::class)],
            'application_system' => ['sometimes', 'nullable', Rule::in(Application::SYSTEMS)],
            'portal_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'cycle_label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'submission_evidence_type' => ['sometimes', 'nullable', new Enum(SubmissionEvidence::class)],
            'submission_evidence_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($application, $validated, $expectedRevision, $source) {
            /** @var Application $locked */
            $locked = Application::query()->lockForUpdate()->findOrFail($application->getKey());

            $revisionBefore = (int) $locked->revision;

            if ($expectedRevision !== null && $expectedRevision !== $revisionBefore) {
                throw new StaleRevisionException('application', $expectedRevision, $revisionBefore);
            }

            $currentStatus = $locked->status;

            foreach ($validated as $key => $value) {
                $locked->setAttribute($key, $value);
            }

            $nextStatus = $locked->status;

            if (! $currentStatus->canTransitionTo($nextStatus)) {
                throw ValidationException::withMessages([
                    'status' => "An application cannot move from {$currentStatus->label()} to {$nextStatus->label()}.",
                ]);
            }

            if ($nextStatus === ApplicationStatus::Submitted && $currentStatus !== ApplicationStatus::Submitted) {
                if ($locked->submission_evidence_type === null) {
                    throw ValidationException::withMessages([
                        'submission_evidence_type' => 'Record how you know it was submitted (portal receipt or your own confirmation) before marking it submitted.',
                    ]);
                }

                $locked->submitted_at = now();
            }

            $changes = [];

            foreach (array_keys($locked->getDirty()) as $key) {
                $changes[$key] = [$this->plain($locked->getOriginal($key)), $this->plain($locked->getAttribute($key))];
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->revision = $revisionBefore + 1;
            $locked->save();

            $this->audit->record((int) $locked->user_id, $source, 'application.updated', $locked, $revisionBefore, $locked->revision, $changes);

            return $locked;
        });
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value);
    }
}
