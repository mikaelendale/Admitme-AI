<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Discovery\CollegeAssessor;
use App\Domain\Memory\MemoryReader;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadCollegeListTool implements ReadOnlyTool, Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Read the student\'s college list: each college\'s list status (suggested, shortlisted, applying, archived), tier, saved deadlines, cycle, portal link, notes and application status, plus the same fit check the student sees on screen: reasons (each with its basis — the student\'s profile, a source on file, or an unverified AI estimate) and an affordability status (within_budget, needs_aid, over_budget or incomplete, with what is missing). Never present an incomplete affordability check as a conclusion. Saved deadlines on the list are not verified against official sources — say so when it matters.';
    }

    public function handle(Request $request): Stringable|string
    {
        $status = $request['status'] ?? null;
        $statuses = is_string($status) && $status !== 'active' ? array_filter([CollegeChoiceStatus::tryFrom($status)]) : null;
        $colleges = app(MemoryReader::class)->collegeList($this->user, $statuses !== [] ? $statuses : null);

        $schools = CollegeListSchool::query()->where('user_id', $this->user->getKey())->whereIn('id', array_column($colleges, 'id'))->get();
        $assessments = app(CollegeAssessor::class)->assessMany($this->user, $schools);

        foreach ($colleges as &$college) {
            $assessment = $assessments[$college['id']] ?? null;

            if ($assessment !== null) {
                $college['fit_check'] = [
                    'reasons' => array_map(fn (array $r) => ['status' => $r['status'], 'text' => $r['text'], 'basis' => $r['basisLabel']], $assessment['reasons']),
                    'affordability' => [
                        'status' => $assessment['affordability']['status'],
                        'complete' => $assessment['affordability']['complete'],
                        'summary' => $assessment['affordability']['summary'],
                        'missing' => $assessment['affordability']['missing'],
                    ],
                    'requirements_on_file' => $assessment['requirements']['onFile'],
                ];
            }
        }

        unset($college);

        return json_encode(['colleges' => $colleges], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['active', ...array_map(fn (CollegeChoiceStatus $s) => $s->value, CollegeChoiceStatus::cases())])
                ->description('"active" (everything not archived) or one list status.')
                ->required(),
        ];
    }
}
