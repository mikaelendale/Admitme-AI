<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\ChangeSource;
use App\Domain\Shared\StaleRevisionException;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Shared write path for a student's college choices — used by the College lists screens and chat tools.
 */
final class CollegeListService
{
    /**
     * Legacy `application_status` values that mean the student is actively applying.
     *
     * @var list<string>
     */
    private const APPLYING_STATUSES = ['applying', 'submitted', 'accepted', 'rejected', 'waitlisted'];

    /**
     * Saved details a student (or an approved proposal) can set or clear.
     *
     * @var list<string>
     */
    private const CLEARABLE_TEXT = ['ea_deadline', 'ed_deadline', 'rd_deadline', 'application_portal_url', 'admissions_cycle_label'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApplicationService $applications,
    ) {}

    /**
     * Add a college the student chose themselves. Re-adding an archived college restores it.
     *
     * @throws ValidationException when the college is already on the list or the name is unusable.
     */
    public function addManual(User $student, string $name, string $tier, ?string $notes, ChangeSource $source): CollegeListSchool
    {
        $name = trim($name);
        $slug = Str::slug($name);

        if ($slug === '') {
            throw ValidationException::withMessages(['school_name' => 'Could not derive a school identifier from that name.']);
        }

        return DB::transaction(function () use ($student, $name, $slug, $tier, $notes, $source) {
            $existing = CollegeListSchool::query()
                ->where('user_id', $student->getKey())
                ->where('school_slug', $slug)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->choice_status !== CollegeChoiceStatus::Archived) {
                throw ValidationException::withMessages(['school_name' => 'A school with a similar name is already on your list.']);
            }

            if ($existing !== null) {
                return $this->update($existing, [
                    'choice_status' => CollegeChoiceStatus::Shortlisted->value,
                    'tier' => $tier,
                    'student_notes' => $notes,
                ], null, $source);
            }

            $school = CollegeListSchool::query()->create([
                'user_id' => $student->getKey(),
                'school_name' => $name,
                'school_slug' => $slug,
                'tier' => $tier,
                'fit_score' => 50,
                'why_blurb' => '',
                'student_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                'application_status' => 'not_started',
                'choice_status' => CollegeChoiceStatus::Shortlisted,
                'is_removed' => false,
                'generated_at' => now(),
            ]);

            $this->audit->record((int) $student->getKey(), $source, 'college_choice.added', $school, null, 1, [
                'choice_status' => [null, CollegeChoiceStatus::Shortlisted->value],
            ], ['college' => $name]);

            return $school;
        });
    }

    /**
     * Add a college the student approved from an agent proposal. Never creates a duplicate: a college
     * already on the list is reported as `already_present`; an archived one is restored.
     *
     * @return array{0: CollegeListSchool|null, 1: 'applied'|'already_present'}
     */
    public function addFromProposal(User $student, string $name, string $tier, ?string $reason, ChangeSource $source): array
    {
        $name = trim($name);
        $slug = Str::slug($name);

        return DB::transaction(function () use ($student, $name, $slug, $tier, $reason, $source) {
            $existing = CollegeListSchool::query()
                ->where('user_id', $student->getKey())
                ->where('school_slug', $slug)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && in_array($existing->choice_status, [CollegeChoiceStatus::Shortlisted, CollegeChoiceStatus::Applying], true)) {
                return [$existing, 'already_present'];
            }

            // A research suggestion or an archived choice is promoted onto the shortlist...
            if ($existing !== null) {
                return [$this->update($existing, ['choice_status' => CollegeChoiceStatus::Shortlisted->value, 'tier' => $tier], null, $source), 'applied'];
            }

            $school = CollegeListSchool::query()->create([
                'user_id' => $student->getKey(),
                'school_name' => $name,
                'school_slug' => $slug,
                'tier' => $tier,
                'fit_score' => 50,
                'why_blurb' => $reason !== null && trim($reason) !== '' ? trim($reason) : '',
                'application_status' => 'not_started',
                'choice_status' => CollegeChoiceStatus::Shortlisted,
                'is_removed' => false,
                'generated_at' => now(),
            ]);

            $this->audit->record((int) $student->getKey(), $source, 'college_choice.added', $school, null, 1, [
                'choice_status' => [null, CollegeChoiceStatus::Shortlisted->value],
            ], ['college' => $name]);

            return [$school, 'applied'];
        });
    }

    /**
     * Change a college choice. Moving it to `applying` opens its application exactly once.
     *
     * @param  array<string, mixed>  $changes  Any of: choice_status, application_status, student_notes, tier,
     *                                         ea_deadline / ed_deadline / rd_deadline (Y-m-d or null),
     *                                         application_portal_url (https or null), admissions_cycle_label.
     *
     * @throws ValidationException
     * @throws StaleRevisionException
     */
    public function update(CollegeListSchool $school, array $changes, ?int $expectedRevision, ChangeSource $source): CollegeListSchool
    {
        // Blank text clears a saved deadline, link or cycle rather than failing validation...
        foreach (self::CLEARABLE_TEXT as $key) {
            if (array_key_exists($key, $changes) && is_string($changes[$key])) {
                $changes[$key] = trim($changes[$key]) === '' ? null : trim($changes[$key]);
            }
        }

        $validated = Validator::make($changes, [
            'choice_status' => ['sometimes', new Enum(CollegeChoiceStatus::class)],
            'application_status' => ['sometimes', 'nullable', Rule::in(CollegeListSchool::APPLICATION_STATUSES)],
            'student_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tier' => ['sometimes', Rule::in(['reach', 'match', 'safety'])],
            'ea_deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ed_deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'rd_deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'rolling_admission' => ['sometimes', 'boolean'],
            'application_portal_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'admissions_cycle_label' => ['sometimes', 'nullable', 'string', 'max:64'],
        ], [
            'date_format' => 'Use a real date written as YYYY-MM-DD.',
            'application_portal_url.url' => 'Use a full https:// link to the application portal.',
        ])->validate();

        return DB::transaction(function () use ($school, $validated, $expectedRevision, $source) {
            /** @var CollegeListSchool $locked */
            $locked = CollegeListSchool::query()->lockForUpdate()->findOrFail($school->getKey());

            $revisionBefore = (int) $locked->revision;

            if ($expectedRevision !== null && $expectedRevision !== $revisionBefore) {
                throw new StaleRevisionException('college choice', $expectedRevision, $revisionBefore);
            }

            $currentChoice = $locked->choice_status;

            if (array_key_exists('student_notes', $validated)) {
                $validated['student_notes'] = is_string($validated['student_notes']) && trim($validated['student_notes']) !== ''
                    ? trim($validated['student_notes'])
                    : null;
            }

            foreach ($validated as $key => $value) {
                $locked->setAttribute($key, $value);
            }

            // The older progress dropdown and the choice status describe the same thing; keep them in step...
            if (! array_key_exists('choice_status', $validated)
                && in_array($validated['application_status'] ?? null, self::APPLYING_STATUSES, true)
                && $currentChoice === CollegeChoiceStatus::Shortlisted) {
                $locked->choice_status = CollegeChoiceStatus::Applying;
            }

            $nextChoice = $locked->choice_status;

            if (! $currentChoice->canTransitionTo($nextChoice)) {
                throw ValidationException::withMessages([
                    'choice_status' => "A college cannot move from {$currentChoice->label()} to {$nextChoice->label()}.",
                ]);
            }

            if ($nextChoice === CollegeChoiceStatus::Applying && in_array($locked->application_status, [null, 'not_started', 'researching'], true)) {
                $locked->application_status = 'applying';
            }

            $locked->is_removed = $nextChoice === CollegeChoiceStatus::Archived;

            $changes = [];

            foreach (array_keys($locked->getDirty()) as $key) {
                $changes[$key] = [$this->plain($locked->getOriginal($key)), $this->plain($locked->getAttribute($key))];
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->revision = $revisionBefore + 1;
            $locked->save();

            $this->audit->record((int) $locked->user_id, $source, 'college_choice.updated', $locked, $revisionBefore, $locked->revision, $changes, [
                'college' => $locked->school_name,
            ]);

            if ($nextChoice === CollegeChoiceStatus::Applying) {
                $this->applications->openFor($locked, $source);
            }

            return $locked;
        });
    }

    /**
     * Decide on research suggestions in one go: add some to the shortlist and dismiss others ("approve 6 of
     * 10"). Only rows that are still suggestions change; anything else is reported back as skipped, so a
     * repeated or stale request never moves a college the student already decided on.
     *
     * @param  list<string>  $add
     * @param  list<string>  $dismiss
     * @return array{added: list<string>, dismissed: list<string>, skipped: array<string, string>}
     *
     * @throws ValidationException
     */
    public function reviewSuggestions(User $student, array $add, array $dismiss, ChangeSource $source): array
    {
        if (array_intersect($add, $dismiss) !== []) {
            throw ValidationException::withMessages(['add' => 'A suggestion cannot be both added and dismissed.']);
        }

        return DB::transaction(function () use ($student, $add, $dismiss, $source) {
            $rows = CollegeListSchool::query()
                ->where('user_id', $student->getKey())
                ->whereIn('id', [...$add, ...$dismiss])
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (CollegeListSchool $s) => (string) $s->id);

            $receipt = ['added' => [], 'dismissed' => [], 'skipped' => []];

            foreach ([[$add, CollegeChoiceStatus::Shortlisted, 'added'], [$dismiss, CollegeChoiceStatus::Archived, 'dismissed']] as [$ids, $status, $bucket]) {
                foreach (array_unique($ids) as $id) {
                    $row = $rows->get($id);

                    if ($row === null) {
                        $receipt['skipped'][$id] = 'not_found';
                    } elseif ($row->choice_status !== CollegeChoiceStatus::Suggested) {
                        $receipt['skipped'][$id] = 'already_'.$row->choice_status->value;
                    } else {
                        $this->update($row, ['choice_status' => $status->value], null, $source);
                        $receipt[$bucket][] = $id;
                    }
                }
            }

            return $receipt;
        });
    }

    /**
     * Archive a college choice (the list's "remove" action). Its history and application are kept.
     */
    public function archive(CollegeListSchool $school, ?int $expectedRevision, ChangeSource $source): CollegeListSchool
    {
        return $this->update($school, ['choice_status' => CollegeChoiceStatus::Archived->value], $expectedRevision, $source);
    }

    /**
     * Insert or update a college choice on behalf of the chat agent's legacy upsert tool.
     *
     * Kept for the existing mentor behaviour until approval proposals (Phase 3) replace direct agent writes.
     *
     * @param  array<string, mixed>  $attributes  Validated by the tool.
     */
    public function upsertFromAgentTool(User $student, string $slug, array $attributes, ChangeSource $source): CollegeListSchool
    {
        return DB::transaction(function () use ($student, $slug, $attributes, $source) {
            $existing = CollegeListSchool::query()
                ->where('user_id', $student->getKey())
                ->where('school_slug', $slug)
                ->lockForUpdate()
                ->first();

            $school = $existing ?? new CollegeListSchool([
                'user_id' => $student->getKey(),
                'school_slug' => $slug,
                'choice_status' => CollegeChoiceStatus::Shortlisted,
            ]);

            $revisionBefore = $existing !== null ? (int) $existing->revision : null;

            $school->fill($attributes);

            if ($existing !== null && $existing->choice_status === CollegeChoiceStatus::Archived && ($attributes['is_removed'] ?? null) === false) {
                $school->choice_status = CollegeChoiceStatus::Shortlisted;
            }

            $changes = [];

            foreach (array_keys($school->getDirty()) as $key) {
                $changes[$key] = [$existing !== null ? $this->plain($school->getOriginal($key)) : null, $this->plain($school->getAttribute($key))];
            }

            if ($existing !== null && $changes === []) {
                return $school;
            }

            if ($existing !== null) {
                $school->revision = $revisionBefore + 1;
            }

            $school->save();

            $this->audit->record((int) $student->getKey(), $source, $existing !== null ? 'college_choice.updated' : 'college_choice.added', $school, $revisionBefore, (int) $school->revision, $changes, [
                'college' => $school->school_name,
            ]);

            return $school;
        });
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value);
    }
}
