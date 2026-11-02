<?php

namespace App\Ai\Tools;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Domain\Applications\CollegeListService;
use App\Domain\Shared\ChangeSource;
use App\Models\CollegeListSchool;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpsertStudentCollegeList implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        $path = '/college-lists';

        return <<<TXT
Create or update one row in this student's saved college list (matched by school name → slug for this user). Use after the student agrees to add or change a school. After saving, tell them they can open their list at {$path}. Never invent deadlines or outcomes; only store what you can justify from the conversation and profile context.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $schoolName = trim($request->string('school_name')->toString());
        if ($schoolName === '') {
            return json_encode(['error' => 'school_name is required and cannot be empty.'], JSON_UNESCAPED_UNICODE);
        }

        $listType = $request->string('list_type')->toString();
        if (! in_array($listType, CollegeListSchool::LIST_TYPES, true)) {
            return json_encode([
                'error' => 'list_type must be one of: '.implode(', ', CollegeListSchool::LIST_TYPES),
            ], JSON_UNESCAPED_UNICODE);
        }

        $slug = Str::slug($schoolName);
        if ($slug === '') {
            return json_encode(['error' => 'Could not derive a school slug from school_name.'], JSON_UNESCAPED_UNICODE);
        }

        $tier = CollegeListSchool::listTypeToTier($listType);

        $existing = CollegeListSchool::query()
            ->where('user_id', $this->user->id)
            ->where('school_slug', $slug)
            ->first();

        $attributes = [
            'school_name' => $schoolName,
            'tier' => $tier,
            'is_removed' => false,
            'generated_at' => now(),
        ];

        if ($existing === null) {
            $attributes['fit_score'] = 50;
            $attributes['why_blurb'] = 'Added from chat.';
        }

        $fit = $request['fit_score'] ?? null;
        if ($fit !== null && $fit !== '') {
            $n = (int) $fit;
            $attributes['fit_score'] = min(100, max(0, $n));
        }

        $reasoning = $request['ai_reasoning'] ?? null;
        if ($reasoning !== null && $reasoning !== '') {
            $attributes['why_blurb'] = (string) $reasoning;
        }

        $status = $request['status'] ?? null;
        if ($status !== null && $status !== '') {
            $s = (string) $status;
            if (! in_array($s, CollegeListSchool::APPLICATION_STATUSES, true)) {
                return json_encode([
                    'error' => 'status must be one of: '.implode(', ', CollegeListSchool::APPLICATION_STATUSES),
                ], JSON_UNESCAPED_UNICODE);
            }
            $attributes['application_status'] = $s;
        }

        $deadline = $request['deadline'] ?? null;
        if ($deadline !== null && $deadline !== '') {
            try {
                $attributes['rd_deadline'] = Carbon::parse((string) $deadline)->toDateString();
            } catch (\Throwable) {
                return json_encode(['error' => 'deadline must be a valid date (e.g. Y-m-d).'], JSON_UNESCAPED_UNICODE);
            }
        }

        $notes = $request['notes'] ?? null;
        if ($notes !== null && $notes !== '') {
            $attributes['student_notes'] = (string) $notes;
        }

        $metaRaw = $request['ai_metadata_json'] ?? null;
        if ($metaRaw !== null && $metaRaw !== '') {
            $decoded = json_decode((string) $metaRaw, true);
            if (! is_array($decoded)) {
                return json_encode(['error' => 'ai_metadata_json must be valid JSON object/array.'], JSON_UNESCAPED_UNICODE);
            }
            $attributes['student_metadata'] = $decoded;
        }

        $portal = $request['application_portal_url'] ?? null;
        if ($portal !== null && $portal !== '') {
            $p = trim((string) $portal);
            if (
                strlen($p) > 2048
                || ! str_starts_with(strtolower($p), 'https://')
                || ! filter_var($p, FILTER_VALIDATE_URL)
            ) {
                return json_encode(['error' => 'application_portal_url must be a valid https URL (max 2048 chars).'], JSON_UNESCAPED_UNICODE);
            }
            $attributes['application_portal_url'] = $p;
        }

        $cycleLabel = $request['admissions_cycle_label'] ?? null;
        if ($cycleLabel !== null && $cycleLabel !== '') {
            $c = trim((string) $cycleLabel);
            if (strlen($c) > 64) {
                return json_encode(['error' => 'admissions_cycle_label must be at most 64 characters.'], JSON_UNESCAPED_UNICODE);
            }
            $attributes['admissions_cycle_label'] = $c;
        }

        $row = app(CollegeListService::class)->upsertFromAgentTool(
            $this->user,
            $slug,
            $attributes,
            ChangeSource::agentTool(AdmitMeGeneralAgent::class, class_basename(self::class)),
        );

        $row->refresh();

        $payload = [
            'ok' => true,
            'id' => $row->id,
            'school_name' => $row->school_name,
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
            'college_lists_path' => '/college-lists',
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $listValues = implode(', ', CollegeListSchool::LIST_TYPES);
        $statusValues = implode(', ', CollegeListSchool::APPLICATION_STATUSES);

        return [
            'school_name' => $schema->string()
                ->description('Official-style school name, e.g. "Stanford University".')
                ->required(),
            'list_type' => $schema->string()
                ->description("One of: {$listValues}.")
                ->required(),
            'fit_score' => $schema->integer()
                ->description('Optional 0–100 fit score, or null to leave unchanged on update / omit.')
                ->nullable()
                ->required(),
            'ai_reasoning' => $schema->string()
                ->description('Short saved rationale for why this bucket fits the student, or null.')
                ->nullable()
                ->required(),
            'status' => $schema->string()
                ->description("Optional workflow status, or null. Allowed: {$statusValues}.")
                ->nullable()
                ->required(),
            'deadline' => $schema->string()
                ->description('Optional regular-decision-style deadline as Y-m-d (stored as rd_deadline), or null.')
                ->nullable()
                ->required(),
            'notes' => $schema->string()
                ->description('Optional private notes for the student, or null.')
                ->nullable()
                ->required(),
            'ai_metadata_json' => $schema->string()
                ->description('Optional JSON string for extra structured fields (e.g. scholarships). Null if none.')
                ->nullable()
                ->required(),
            'application_portal_url' => $schema->string()
                ->description('Optional https application portal URL, or null.')
                ->nullable()
                ->required(),
            'admissions_cycle_label' => $schema->string()
                ->description('Optional short cycle label (e.g. 2026–27), or null.')
                ->nullable()
                ->required(),
        ];
    }
}
