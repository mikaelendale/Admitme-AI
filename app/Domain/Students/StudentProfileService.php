<?php

namespace App\Domain\Students;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\ChangeSource;
use App\Domain\Shared\StaleRevisionException;
use App\Domain\Tasks\Orchestrator;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Services\Profile\GpaNormalizer;
use App\Services\Profile\ProfileCompletenessCalculator;
use App\Services\Profile\ProfileModesResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for a student's profile fields — used by manual screens, approved proposals
 * and document confirmation alike. It validates, enforces the expected revision, recomputes derived
 * values, and records provenance plus an audit event in one transaction.
 */
final class StudentProfileService
{
    public function __construct(
        private readonly GpaNormalizer $gpaNormalizer,
        private readonly ProfileCompletenessCalculator $completeness,
        private readonly ProfileModesResolver $modes,
        private readonly StudentFactRecorder $facts,
        private readonly AuditLogger $audit,
    ) {}

    public function profileFor(User $student): OnboardingProfile
    {
        return OnboardingProfile::query()->firstOrCreate(['user_id' => $student->getKey()], ['status' => 'completed']);
    }

    /**
     * Apply field changes to the student's profile.
     *
     * @param  array<string, mixed>  $changes  Field key (see StudentFieldRegistry) => new value.
     * @param  int|null  $expectedRevision  The revision the change was based on; null skips the stale check.
     * @param  array<string, mixed>  $extraAttributes  Untracked columns saved in the same write (e.g. UI progress).
     *
     * @throws ValidationException when a key is unknown or a value is invalid.
     * @throws StaleRevisionException when the profile changed after `$expectedRevision`.
     */
    public function update(User $student, array $changes, ?int $expectedRevision, ChangeSource $source, array $extraAttributes = []): ProfileUpdateResult
    {
        $validated = $this->validate($changes);

        $result = DB::transaction(function () use ($student, $validated, $expectedRevision, $source, $extraAttributes) {
            $this->profileFor($student);

            /** @var OnboardingProfile $profile */
            $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->lockForUpdate()->firstOrFail();

            $revisionBefore = (int) $profile->revision;

            if ($expectedRevision !== null && $expectedRevision !== $revisionBefore) {
                throw new StaleRevisionException('profile', $expectedRevision, $revisionBefore);
            }

            $changes = [];

            foreach ($validated as $key => $value) {
                $column = StudentFieldRegistry::column($key);
                $before = $profile->getAttribute($column);

                $profile->setAttribute($column, StudentFieldRegistry::normalize($key, $value));

                if ($profile->isDirty($column)) {
                    $changes[$key] = [$before, $profile->getAttribute($column)];
                }
            }

            // A real answer replaces an earlier "don't know"...
            $unknown = (array) ($profile->unknown_fields_json ?? []);
            $answered = array_keys(array_filter($validated, fn (mixed $value) => $value !== null && $value !== '' && $value !== []));

            if (array_intersect($unknown, $answered) !== []) {
                $profile->unknown_fields_json = array_values(array_diff($unknown, $answered));
            }

            $profile->forceFill($extraAttributes);

            // The observer would otherwise record this save as an unattributed legacy write...
            $profile->provenanceHandled = true;

            if ($changes === []) {
                if ($profile->isDirty()) {
                    $profile->save();
                }

                return new ProfileUpdateResult($profile, [], $revisionBefore, $revisionBefore);
            }

            $this->recomputeDerived($profile, array_keys($changes));

            $profile->revision = $revisionBefore + 1;
            $profile->save();

            $this->facts->record((int) $student->getKey(), $changes, $source, $profile->revision);

            $this->audit->record(
                (int) $student->getKey(),
                $source,
                'profile.updated',
                $profile,
                $revisionBefore,
                $profile->revision,
                $changes,
            );

            return new ProfileUpdateResult($profile, $changes, $revisionBefore, $profile->revision);
        });

        // Background work that used the old values (e.g. an affordability-based shortlist) is re-evaluated...
        if ($result->changed()) {
            app(Orchestrator::class)->profileChanged($student, array_keys($result->changes), $result->revisionAfter);
        }

        return $result;
    }

    /**
     * Record which of a section's fields the student answered "don't know / not applicable". Those count as
     * answered for completeness and are shown to the mentor as unknown rather than missing.
     *
     * @param  list<string>  $sectionKeys  Every field the section asked about.
     * @param  list<string>  $unknownKeys  The ones marked unknown now.
     *
     * @throws ValidationException
     * @throws StaleRevisionException
     */
    public function setUnknownFields(User $student, array $sectionKeys, array $unknownKeys, ?int $expectedRevision, ChangeSource $source): ?int
    {
        foreach ([...$sectionKeys, ...$unknownKeys] as $key) {
            if (! StudentFieldRegistry::has($key)) {
                throw ValidationException::withMessages(['unknown' => "Unknown profile field [{$key}]."]);
            }
        }

        return DB::transaction(function () use ($student, $sectionKeys, $unknownKeys, $expectedRevision, $source) {
            $this->profileFor($student);

            /** @var OnboardingProfile $profile */
            $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->lockForUpdate()->firstOrFail();
            $revisionBefore = (int) $profile->revision;

            if ($expectedRevision !== null && $expectedRevision !== $revisionBefore) {
                throw new StaleRevisionException('profile', $expectedRevision, $revisionBefore);
            }

            $before = array_values((array) ($profile->unknown_fields_json ?? []));
            $after = array_values(array_unique([...array_diff($before, $sectionKeys), ...array_intersect($unknownKeys, $sectionKeys)]));
            sort($after);
            $sortedBefore = $before;
            sort($sortedBefore);

            if ($after === $sortedBefore) {
                return $revisionBefore;
            }

            $profile->unknown_fields_json = $after;
            $profile->revision = $revisionBefore + 1;
            $profile->provenanceHandled = true;
            $profile->save();

            $this->audit->record((int) $student->getKey(), $source, 'profile.unknowns_updated', $profile, $revisionBefore, $profile->revision, [
                'unknown_fields' => [$sortedBefore, $after],
            ]);

            return (int) $profile->revision;
        });
    }

    /**
     * Current values of the tracked fields, keyed by field key.
     *
     * @return array<string, mixed>
     */
    public function values(OnboardingProfile $profile): array
    {
        $values = [];

        foreach (StudentFieldRegistry::fields() as $key => $field) {
            $values[$key] = $profile->getAttribute($field['column']);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $changes): array
    {
        $unknown = array_values(array_filter(array_keys($changes), fn (string|int $key) => ! is_string($key) || ! StudentFieldRegistry::has($key)));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'fields' => 'These profile fields cannot be changed: '.implode(', ', $unknown).'.',
            ]);
        }

        Validator::make($changes, StudentFieldRegistry::rules(array_keys($changes)))->validate();

        return $changes;
    }

    /**
     * @param  list<string>  $changedKeys
     */
    private function recomputeDerived(OnboardingProfile $profile, array $changedKeys): void
    {
        if (array_intersect($changedKeys, ['gpa_value', 'gpa_scale', 'curriculum']) !== []) {
            $normalized = $this->gpaNormalizer->toUsFourPointScale(
                $profile->gpa_value !== null ? (float) $profile->gpa_value : null,
                $profile->gpa_scale !== null ? (float) $profile->gpa_scale : null,
                $profile->curriculum,
            );

            $profile->gpa_normalized_4 = $normalized['gpa_normalized_4'];
        }

        $calculated = $this->completeness->calculate($profile);
        $profile->profile_completeness_score = $calculated['score'];
        $profile->completeness_callouts_json = $calculated['callouts'];
        $profile->profile_modes_json = $this->modes->resolve($profile);
    }
}
