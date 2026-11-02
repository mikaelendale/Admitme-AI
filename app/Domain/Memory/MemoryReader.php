<?php

namespace App\Domain\Memory;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Domain\Students\StudentFieldRegistry;
use App\Models\Application;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Read-only, student-scoped projections of memory (the plan's profile.json / preferences.json /
 * applications views). The student is always passed in by the application — never chosen by a model.
 *
 * Profile projections are cached by student, section, profile revision and schema version, so any
 * manual or approved edit (which bumps the revision) is visible on the next read.
 */
final class MemoryReader
{
    /**
     * @var list<string>
     */
    public const SECTIONS = [
        StudentFieldRegistry::SECTION_ACADEMICS,
        StudentFieldRegistry::SECTION_IDENTITY,
        StudentFieldRegistry::SECTION_PREFERENCES,
        StudentFieldRegistry::SECTION_NARRATIVE,
        StudentFieldRegistry::SECTION_EXPERIENCE,
    ];

    public function profileRevision(User $student): int
    {
        return (int) (OnboardingProfile::query()->where('user_id', $student->getKey())->value('revision') ?? 0);
    }

    /**
     * @return array{section: string, profile_revision: int, schema_version: int, fields: list<array<string, mixed>>, missing: list<string>}
     */
    public function profileSection(User $student, string $section): array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException("Unknown memory section [{$section}].");
        }

        $revision = $this->profileRevision($student);
        $schema = (int) config('admitme.context.memory_schema_version', 1);

        return Cache::remember(
            "admitme:memory:{$student->getKey()}:{$section}:r{$revision}:s{$schema}",
            (int) config('admitme.context.memory_cache_ttl_seconds', 3600),
            fn () => $this->buildSection($student, $section, $revision, $schema),
        );
    }

    /**
     * @return array<string, array{section: string, profile_revision: int, schema_version: int, fields: list<array<string, mixed>>, missing: list<string>}>
     */
    public function profile(User $student): array
    {
        $sections = [];

        foreach (self::SECTIONS as $section) {
            $sections[$section] = $this->profileSection($student, $section);
        }

        return $sections;
    }

    /**
     * @param  list<CollegeChoiceStatus>|null  $statuses  Defaults to everything except archived.
     * @return list<array<string, mixed>>
     */
    public function collegeList(User $student, ?array $statuses = null): array
    {
        $statuses ??= [CollegeChoiceStatus::Suggested, CollegeChoiceStatus::Shortlisted, CollegeChoiceStatus::Applying];

        return CollegeListSchool::query()
            ->where('user_id', $student->getKey())
            ->whereIn('choice_status', array_map(fn (CollegeChoiceStatus $s) => $s->value, $statuses))
            ->with('application:id,college_list_school_id,status')
            ->orderBy('school_name')
            ->get()
            ->map(fn (CollegeListSchool $school) => [
                'id' => (string) $school->id,
                'name' => $school->school_name,
                'tier' => $school->tier,
                'list_status' => $school->choice_status->value,
                'progress' => $school->application_status,
                'deadlines' => array_filter([
                    'early_action' => $school->ea_deadline?->toDateString(),
                    'early_decision' => $school->ed_deadline?->toDateString(),
                    'regular_decision' => $school->rd_deadline?->toDateString(),
                ]),
                'cycle' => $school->admissions_cycle_label,
                'portal_url' => $school->application_portal_url,
                'aid_outlook' => $school->aid_estimate_band,
                'student_notes' => $school->student_notes,
                'application_status' => $school->application?->status->value,
                'revision' => (int) $school->revision,
                // Values on this list were written by the student, the mentor or the list council; deadlines
                // and costs here are not verified against official sources yet (Phase 5).
                'verified' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function applications(User $student): array
    {
        return Application::query()
            ->where('user_id', $student->getKey())
            ->with('collegeListSchool:id,school_name,ea_deadline,ed_deadline,rd_deadline')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Application $application) => $this->applicationRow($application))
            ->values()
            ->all();
    }

    /**
     * A single application, or null when it does not exist for this student.
     *
     * @return array<string, mixed>|null
     */
    public function application(User $student, string $applicationId): ?array
    {
        $application = Application::query()
            ->where('user_id', $student->getKey())
            ->whereKey($applicationId)
            ->with('collegeListSchool:id,school_name,ea_deadline,ed_deadline,rd_deadline')
            ->first();

        return $application !== null ? $this->applicationRow($application) : null;
    }

    /**
     * A compact overview for the mentor's context: every tracked field with its provenance, what is
     * missing, and list/application counts.
     *
     * @return array<string, mixed>
     */
    public function brief(User $student): array
    {
        $choices = CollegeListSchool::query()
            ->where('user_id', $student->getKey())
            ->where('choice_status', '!=', CollegeChoiceStatus::Archived->value)
            ->selectRaw('choice_status, count(*) as total')
            ->groupBy('choice_status')
            ->pluck('total', 'choice_status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $applications = Application::query()
            ->where('user_id', $student->getKey())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $pendingCandidates = StudentFact::query()
            ->where('user_id', $student->getKey())
            ->whereIn('status', [FactStatus::Candidate, FactStatus::Conflict])
            ->where('review_state', StudentFact::REVIEW_PENDING)
            ->count();

        return [
            'profile_revision' => $this->profileRevision($student),
            'sections' => $this->profile($student),
            'college_list' => $choices,
            'applications' => $applications,
            'pending_document_values' => $pendingCandidates,
        ];
    }

    /**
     * @return array{section: string, profile_revision: int, schema_version: int, fields: list<array<string, mixed>>, missing: list<string>}
     */
    private function buildSection(User $student, string $section, int $revision, int $schema): array
    {
        $keys = StudentFieldRegistry::keysInSection($section);
        $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->first();

        $facts = StudentFact::query()
            ->where('user_id', $student->getKey())
            ->whereIn('field_key', $keys)
            ->current()
            ->orderBy('id')
            ->get()
            ->keyBy('field_key');

        $fields = [];
        $missing = [];
        $unknown = [];
        $saidUnknown = (array) ($profile?->unknown_fields_json ?? []);

        foreach ($keys as $key) {
            $field = StudentFieldRegistry::get($key);
            $value = $profile?->getAttribute($field['column']);

            if ($value === null || $value === [] || $value === '') {
                // The student told us they don't know or it doesn't apply: answered, not missing.
                if (in_array($key, $saidUnknown, true)) {
                    $unknown[] = $field['label'];
                } else {
                    $missing[] = $field['label'];
                }

                continue;
            }

            /** @var StudentFact|null $fact */
            $fact = $facts->get($key);

            $fields[] = [
                'key' => $key,
                'label' => $field['label'],
                'value' => $value,
                'provenance' => $this->provenance($fact),
                'confirmed' => $fact?->status === FactStatus::Confirmed,
                'recorded_at' => $fact?->created_at?->toDateString(),
            ];
        }

        return [
            'section' => $section,
            'profile_revision' => $revision,
            'schema_version' => $schema,
            'fields' => $fields,
            'missing' => $missing,
            'unknown' => $unknown,
        ];
    }

    private function provenance(?StudentFact $fact): string
    {
        if ($fact === null) {
            return 'source not recorded (saved before tracking began)';
        }

        if ($fact->status === FactStatus::Confirmed) {
            return 'confirmed by the student';
        }

        return match ($fact->source_type) {
            ChangeSource::SOURCE_AI_EXTRACTION => 'extracted by AI from the student\'s text or resume — not yet confirmed',
            ChangeSource::SOURCE_AGENT_TOOL => 'set by the mentor — not yet confirmed',
            default => 'recorded by the system — not yet confirmed',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationRow(Application $application): array
    {
        $school = $application->collegeListSchool;

        return [
            'id' => (string) $application->id,
            'college' => $school?->school_name,
            'status' => $application->status->value,
            'application_system' => $application->application_system,
            'portal_url' => $application->portal_url,
            'cycle' => $application->cycle_label,
            'submitted_at' => $application->submitted_at?->toDateString(),
            'submission_evidence' => $application->submission_evidence_type?->value,
            'notes' => $application->notes,
            'deadlines' => array_filter([
                'early_action' => $school?->ea_deadline?->toDateString(),
                'early_decision' => $school?->ed_deadline?->toDateString(),
                'regular_decision' => $school?->rd_deadline?->toDateString(),
            ]),
            'revision' => (int) $application->revision,
        ];
    }
}
