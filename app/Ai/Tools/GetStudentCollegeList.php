<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetStudentCollegeList implements ReadOnlyTool, Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Read this student's college list from the database (college_list_schools). Returns each row: `id` (ULID — use this verbatim for essay jobs), school name, `list_status`, list type (reach/target/safety), fit score, why text, application workflow status, optional deadlines (EA/ED/RD), private notes, and student_metadata. Use before list balance, deadlines, or **before StartEssayRunTool** so you use real ids—not guesses.
`list_status` matters: "shortlisted" and "applying" are on the student's saved list; "suggested" came from research and is NOT on the saved list until the student adds it — never call a suggestion saved, and propose it like any new college if the student wants it.

If the response says there are no colleges: **do not call StartEssayRunTool**; explain the list is empty and offer to help build it (e.g. council). If there are several schools and the student has not picked one for the essay run, ask **which college** and show names with their `id`s from this tool.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $query = CollegeListSchool::query()
            ->where('user_id', $this->user->id)
            ->where('is_removed', false);

        if ($request->filled('list_type')) {
            $type = $request->string('list_type')->toString();
            if (in_array($type, CollegeListSchool::LIST_TYPES, true)) {
                $query->where('tier', CollegeListSchool::listTypeToTier($type));
            }
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if (in_array($status, CollegeListSchool::APPLICATION_STATUSES, true)) {
                $query->where('application_status', $status);
            }
        }

        if ($request->filled('school_name_contains')) {
            $needle = $request->string('school_name_contains')->toString();
            if ($needle !== '') {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where('school_name', 'like', $like);
            }
        }

        $rawLimit = $request['limit'] ?? null;
        $limit = $rawLimit === null || $rawLimit === '' ? 50 : (int) $rawLimit;
        $limit = min(100, max(1, $limit));

        $rows = $query
            ->orderByRaw("CASE tier WHEN 'reach' THEN 1 WHEN 'match' THEN 2 WHEN 'safety' THEN 3 ELSE 4 END")
            ->orderBy('school_name')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return <<<'TXT'
No colleges found for this student with the given filters. The saved list may be empty, or filters excluded every row.

Do not call StartEssayRunTool until there is at least one active list row and the student has indicated which school the essay run is for (copy that row’s `id` from a future GetStudentCollegeList response).
TXT;
        }

        $payload = $rows->map(fn (CollegeListSchool $row) => [
            'id' => $row->id,
            'school_name' => $row->school_name,
            'school_slug' => $row->school_slug,
            'list_status' => $row->choice_status->value,
            'on_saved_list' => $row->choice_status !== CollegeChoiceStatus::Suggested,
            'list_type' => CollegeListSchool::tierToListType($row->tier),
            'fit_score' => $row->fit_score,
            'ai_reasoning' => $row->why_blurb,
            'status' => $row->application_status,
            'ea_deadline' => $row->ea_deadline?->toDateString(),
            'ed_deadline' => $row->ed_deadline?->toDateString(),
            'rd_deadline' => $row->rd_deadline?->toDateString(),
            'application_portal_url' => $row->application_portal_url,
            'admissions_cycle_label' => $row->admissions_cycle_label,
            'notes' => $row->student_notes,
            'ai_metadata' => is_array($row->student_metadata) ? $row->student_metadata : [],
            'generated_at' => $row->generated_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ]);

        return $payload->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $listValues = implode(', ', CollegeListSchool::LIST_TYPES);
        $statusValues = implode(', ', CollegeListSchool::APPLICATION_STATUSES);

        return [
            'list_type' => $schema->string()
                ->description("Filter to one list bucket, or null for all. Allowed: {$listValues}.")
                ->nullable()
                ->required(),
            'status' => $schema->string()
                ->description("Filter by application status, or null for any. Allowed: {$statusValues}.")
                ->nullable()
                ->required(),
            'school_name_contains' => $schema->string()
                ->description('Substring match on school_name (SQL LIKE), or null for no text filter.')
                ->nullable()
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum rows to return (1–100). Use null for default 50.')
                ->nullable()
                ->required(),
        ];
    }
}
