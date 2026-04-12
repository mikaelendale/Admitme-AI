<?php

namespace App\Services\GapFix;

use App\Ai\Agents\GapFixWhatIfExplainAgent;
use App\Models\CollegeListSchool;
use App\Models\User;
use App\Services\CollegeListCouncil\StructuredAgentResponseMapper;
use App\Support\GapFix\GapFixPromptBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Promptable;

final class GapFixWhatIfExplainService
{
    /**
     * @param  list<string>  $selectedSchoolIds
     */
    public function explain(User $user, array $selectedSchoolIds, int $satDelta, float $gpaDelta): string
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $selectedSchoolIds))));
        if ($ids === [] || count($ids) > 12) {
            throw ValidationException::withMessages([
                'college_list_school_ids' => 'Select between 1 and 12 schools.',
            ]);
        }

        /** @var Collection<int, CollegeListSchool> $schools */
        $schools = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->where('is_removed', false)
            ->whereIn('id', $ids)
            ->get();

        if ($schools->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'college_list_school_ids' => 'Invalid school selection.',
            ]);
        }

        $ordered = [];
        foreach ($ids as $id) {
            $row = $schools->firstWhere('id', $id);
            if ($row !== null) {
                $ordered[] = $row;
            }
        }
        $schools = collect($ordered);

        $profile = $user->onboardingProfile;
        $baseSat = $profile?->sat_score;
        $baseGpa = $profile?->gpa_normalized_4 !== null ? (float) $profile->gpa_normalized_4 : null;

        $hypoSat = $baseSat !== null ? max(400, min(1600, $baseSat + $satDelta)) : null;
        $hypoGpa = $baseGpa !== null ? max(0.0, min(4.0, round($baseGpa + $gpaDelta, 2))) : null;

        $snap = GapFixPromptBuilder::build($user, $schools);

        $envelope = [
            'deltas' => [
                'sat_delta' => $satDelta,
                'gpa_delta' => $gpaDelta,
            ],
            'hypothetical' => [
                'sat_score' => $hypoSat,
                'gpa_normalized_4' => $hypoGpa,
            ],
            'baseline' => [
                'sat_score' => $baseSat,
                'gpa_normalized_4' => $baseGpa,
            ],
            'student_profile' => $snap['student_profile'],
            'schools' => $snap['schools'],
        ];

        if (config('gap_fix.fake')) {
            return "## What-if (stub)\n\nHypothetical SAT: **".($hypoSat ?? 'n/a').'**, GPA (4.0): **'.($hypoGpa ?? 'n/a')."**. Compare to each school's medians in the table — `GAP_FIX_FAKE=true` skips the live model.";
        }

        $prompt = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        /** @var Promptable&object $agent */
        $agent = GapFixWhatIfExplainAgent::make();
        $response = $agent->prompt($prompt);
        $data = StructuredAgentResponseMapper::toArray($response);
        $n = isset($data['narrative']) && is_string($data['narrative']) ? trim($data['narrative']) : '';

        if ($n === '') {
            throw ValidationException::withMessages([
                'narrative' => 'Model returned an empty explanation.',
            ]);
        }

        return $n;
    }
}
