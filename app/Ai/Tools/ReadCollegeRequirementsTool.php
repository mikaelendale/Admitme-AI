<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Knowledge\RequirementOverview;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Source-backed requirements for a college on the student's own list, with what is still unresolved.
 */
class ReadCollegeRequirementsTool implements ReadOnlyTool, Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Read the admissions requirements on file for one college on the student\'s list (deadlines, fees, testing policy…), each with its cycle, source page and status, plus what is unresolved (missing, conflicting or stale). Use this instead of memory for any college-specific fact, and cite the source. If something is unresolved, say so — never guess.';
    }

    public function handle(Request $request): Stringable|string
    {
        $name = trim($request->string('college_name')->toString());

        $school = CollegeListSchool::query()
            ->where('user_id', $this->user->getKey())
            ->where('choice_status', '!=', CollegeChoiceStatus::Archived->value)
            ->get()
            ->first(fn (CollegeListSchool $s) => mb_strtolower($s->school_name) === mb_strtolower($name))
            ?? CollegeListSchool::query()
                ->where('user_id', $this->user->getKey())
                ->where('choice_status', '!=', CollegeChoiceStatus::Archived->value)
                ->where('school_name', 'like', '%'.$name.'%')
                ->first();

        if ($school === null || $name === '') {
            return json_encode(['error' => 'That college is not on the student\'s list.']) ?: '{}';
        }

        $cycle = OnboardingProfile::query()->where('user_id', $this->user->getKey())->value('target_intake') ?: $school->admissions_cycle_label;
        $overview = app(RequirementOverview::class)->for(app(InstitutionResolver::class)->findForSchool($school), $cycle, $this->user);

        return json_encode([
            'college' => $school->school_name,
            'student_cycle' => $cycle,
            'requirements' => array_map(fn (array $r) => array_intersect_key($r, array_flip(['label', 'value', 'conditions', 'scope', 'program', 'cycle', 'cycleMatches', 'status', 'quote', 'source'])), $overview['requirements']),
            'unresolved' => $overview['unresolved'],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'college_name' => $schema->string()->description('A college on the student\'s list, by name.')->required(),
        ];
    }
}
