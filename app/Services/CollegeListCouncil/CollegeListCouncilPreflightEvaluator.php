<?php

namespace App\Services\CollegeListCouncil;

use App\Ai\Agents\ContextualAuditorAgent;
use App\Ai\Tools\GetStudentAdmissionContextTool;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Runs the same contextual-audit preflight as {@see RealCollegeListCouncilOrchestrator}
 * so chat can gate the council job on {@see ContextualAuditorAgent}'s info_gaps before dispatch.
 */
class CollegeListCouncilPreflightEvaluator
{
    /**
     * @return array<string, mixed>
     */
    public function evaluate(User $user, string $studentRequest): array
    {
        $contextTool = new GetStudentAdmissionContextTool($user);
        $studentContext = $contextTool->buildPayload($user, 'full');

        $auditor = ContextualAuditorAgent::make()->prompt(json_encode([
            'student_context' => $studentContext,
            'student_request' => $studentRequest,
        ], JSON_UNESCAPED_UNICODE));

        return StructuredAgentResponseMapper::toArray($auditor);
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return list<string>
     */
    public function normalizedInfoGaps(array $preflight): array
    {
        $gaps = array_slice(Arr::wrap($preflight['info_gaps'] ?? []), 0, 5);
        $out = [];
        foreach ($gaps as $gap) {
            if (is_string($gap) && $gap !== '') {
                $out[] = $gap;
            }
        }

        return $out;
    }
}
