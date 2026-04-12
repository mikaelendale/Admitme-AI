<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_college_lists') || ! Schema::hasTable('college_list_schools')) {
            return;
        }

        $rows = DB::table('user_college_lists')->orderBy('id')->get();

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $schoolName = (string) $row->school_name;
            $slug = $this->uniqueSlugForUser($userId, Str::slug($schoolName));

            $tier = match ((string) $row->list_type) {
                'target' => 'match',
                'reach' => 'reach',
                'safety' => 'safety',
                default => 'match',
            };

            $why = trim((string) ($row->ai_reasoning ?? ''));
            if ($why === '') {
                $why = 'Imported from your previous college list.';
            }

            $fit = $row->fit_score !== null ? (int) $row->fit_score : 50;
            $fit = max(0, min(100, $fit));

            $now = $row->added_at ?? now();

            DB::table('college_list_schools')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => $userId,
                'college_list_run_id' => null,
                'school_name' => $schoolName,
                'school_slug' => $slug,
                'tier' => $tier,
                'fit_score' => $fit,
                'why_blurb' => $why,
                'acceptance_rate' => null,
                'median_gpa' => null,
                'median_sat' => null,
                'median_act' => null,
                'ea_deadline' => null,
                'ed_deadline' => null,
                'rd_deadline' => $row->deadline,
                'aid_estimate_band' => null,
                'notable_programs' => null,
                'campus_size' => null,
                'location_city' => null,
                'location_state' => null,
                'is_removed' => false,
                'application_status' => (string) $row->status,
                'student_notes' => $row->notes,
                'student_metadata' => $this->jsonOrNull($row->ai_metadata),
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);
        }
    }

    /**
     * @return non-empty-string
     */
    protected function uniqueSlugForUser(int $userId, string $baseSlug): string
    {
        $slug = $baseSlug !== '' ? $baseSlug : 'school';
        $candidate = $slug;
        $n = 2;

        while (DB::table('college_list_schools')
            ->where('user_id', $userId)
            ->where('school_slug', $candidate)
            ->exists()) {
            $candidate = $slug.'-'.$n;
            $n++;
        }

        return $candidate;
    }

    protected function jsonOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            return $json === false ? null : $json;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    public function down(): void
    {
        // Irreversible: cannot restore user_college_lists rows from this migration alone.
    }
};
