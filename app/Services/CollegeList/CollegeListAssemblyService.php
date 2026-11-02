<?php

namespace App\Services\CollegeList;

use App\Ai\Agents\CollegeListAgent;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Models\CollegeListSchool;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class CollegeListAssemblyService
{
    /**
     * @param  array<string, mixed>  $payload
     *                                         Expects keys: action?, schools?, removed_slugs? (same as {@see CollegeListAgent} output).
     */
    public function persistFromAgentPayload(User $user, string $intent, array $payload, ?string $collegeListRunId = null): void
    {
        $action = $payload['action'] ?? 'refine';
        $shouldWipe = $intent === 'generate' || $action === 'generate';

        // Regenerating replaces earlier AI suggestions only. Colleges the student shortlisted or is applying
        // to are theirs; research never removes them (docs/admitme/decisions.md D-012)...
        if ($shouldWipe) {
            CollegeListSchool::query()
                ->where('user_id', $user->id)
                ->where('choice_status', CollegeChoiceStatus::Suggested)
                ->update(['is_removed' => true, 'choice_status' => CollegeChoiceStatus::Archived]);
        }

        $schools = $payload['schools'] ?? [];
        $removedSlugs = $payload['removed_slugs'] ?? [];
        $now = now();

        if (is_array($schools)) {
            foreach ($schools as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->upsertSchoolRow($user, $row, $now, $collegeListRunId);
            }
        }

        if (is_array($removedSlugs)) {
            foreach ($removedSlugs as $slug) {
                if (! is_string($slug) || $slug === '') {
                    continue;
                }
                $normalized = Str::slug($slug);
                if ($normalized === '') {
                    continue;
                }
                CollegeListSchool::query()
                    ->where('user_id', $user->id)
                    ->where('school_slug', $normalized)
                    ->where('choice_status', CollegeChoiceStatus::Suggested)
                    ->update(['is_removed' => true, 'choice_status' => CollegeChoiceStatus::Archived]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function upsertSchoolRow(User $user, array $row, CarbonInterface $now, ?string $collegeListRunId = null, ?string $agentTaskId = null): void
    {
        $name = trim((string) ($row['school_name'] ?? ''));
        $slugRaw = trim((string) ($row['school_slug'] ?? ''));
        $slug = $slugRaw !== '' ? Str::slug($slugRaw) : Str::slug($name);
        if ($slug === '' || $name === '') {
            return;
        }

        $tier = $row['tier'] ?? 'match';
        if (! in_array($tier, ['reach', 'match', 'safety'], true)) {
            $tier = 'match';
        }

        $fit = (int) ($row['fit_score'] ?? 50);
        $fit = max(0, min(100, $fit));

        $programs = $row['notable_programs'] ?? [];
        if (! is_array($programs)) {
            $programs = [];
        }
        $programs = array_values(array_filter(array_map('strval', $programs)));

        $aid = $row['aid_estimate_band'] ?? null;
        if ($aid !== null && $aid !== '' && ! in_array($aid, ['strong', 'moderate', 'limited'], true)) {
            $aid = null;
        }

        $campus = $row['campus_size'] ?? null;
        if ($campus !== null && $campus !== '' && ! in_array($campus, ['small', 'medium', 'large'], true)) {
            $campus = null;
        }

        $state = $row['location_state'] ?? null;
        if (is_string($state)) {
            $state = strtoupper(substr(preg_replace('/[^a-z]/i', '', $state) ?? '', 0, 2));
            $state = strlen($state) === 2 ? $state : null;
        } else {
            $state = null;
        }

        $attributes = [
            'user_id' => $user->id,
            'school_name' => $name,
            'school_slug' => $slug,
            'tier' => $tier,
            'fit_score' => $fit,
            'why_blurb' => (string) ($row['why_blurb'] ?? ''),
            'acceptance_rate' => $this->nullableDecimal(Arr::get($row, 'acceptance_rate')),
            'median_gpa' => $this->nullableDecimal(Arr::get($row, 'median_gpa')),
            'median_sat' => $this->nullableInt(Arr::get($row, 'median_sat')),
            'median_act' => $this->nullableTinyInt(Arr::get($row, 'median_act')),
            'ea_deadline' => $this->nullableDate(Arr::get($row, 'ea_deadline')),
            'ed_deadline' => $this->nullableDate(Arr::get($row, 'ed_deadline')),
            'rd_deadline' => $this->nullableDate(Arr::get($row, 'rd_deadline')),
            'application_portal_url' => $this->nullableHttpsUrl(Arr::get($row, 'application_portal_url')),
            'admissions_cycle_label' => $this->nullableShortLabel(Arr::get($row, 'admissions_cycle_label')),
            'aid_estimate_band' => $aid,
            'notable_programs' => $programs !== [] ? $programs : null,
            'campus_size' => $campus,
            'location_city' => $this->nullableString(Arr::get($row, 'location_city')),
            'location_state' => $state,
            'is_removed' => false,
            'generated_at' => $now,
        ];

        if ($collegeListRunId !== null) {
            $attributes['college_list_run_id'] = $collegeListRunId;
        }

        $existing = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->where('school_slug', $slug)
            ->first();

        // Research output is a suggestion. It refreshes the details of a college the student already saved,
        // but never changes that college's list status...
        $keepsStatus = $existing !== null && in_array($existing->choice_status, [CollegeChoiceStatus::Shortlisted, CollegeChoiceStatus::Applying], true);
        $attributes['choice_status'] = $keepsStatus ? $existing->choice_status : CollegeChoiceStatus::Suggested;

        // Only a suggestion is the task's artifact; a college the student already saved is not.
        if ($agentTaskId !== null && ! $keepsStatus) {
            $attributes['agent_task_id'] = $agentTaskId;
        }

        $school = $existing ?? new CollegeListSchool;
        $school->forceFill($attributes);

        // Any change to a saved row moves its revision, so an edit based on the old values is detected...
        if ($existing !== null && $school->isDirty()) {
            $school->revision = (int) $existing->revision + 1;
        }

        $school->save();
    }

    protected function nullableString(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    protected function nullableDecimal(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return null;
        }

        return (string) $v;
    }

    protected function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return null;
        }

        return (int) $v;
    }

    protected function nullableTinyInt(mixed $v): ?int
    {
        $n = $this->nullableInt($v);
        if ($n === null) {
            return null;
        }

        return max(1, min(36, $n));
    }

    protected function nullableDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    protected function nullableHttpsUrl(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $s = trim($v);
        if ($s === '' || strlen($s) > 2048) {
            return null;
        }
        if (! str_starts_with(strtolower($s), 'https://')) {
            return null;
        }

        return filter_var($s, FILTER_VALIDATE_URL) ? $s : null;
    }

    protected function nullableShortLabel(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $s = trim($v);

        return $s === '' || strlen($s) > 64 ? null : $s;
    }
}
