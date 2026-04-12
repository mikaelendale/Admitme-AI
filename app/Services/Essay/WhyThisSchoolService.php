<?php

namespace App\Services\Essay;

use App\Ai\Agents\WhyThisSchoolAgent;
use App\Models\CollegeListSchool;
use App\Models\User;
use App\Models\WhyThisSchoolDraft;
use App\Services\CollegeListCouncil\StructuredAgentResponseMapper;
use App\Support\EssaySuite\EssayStudentContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Promptable;

final class WhyThisSchoolService
{
    /**
     * @return array{id: string, payload: array<string, mixed>}
     */
    public function generate(User $user, string $collegeListSchoolId): array
    {
        $school = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->where('is_removed', false)
            ->where('id', $collegeListSchoolId)
            ->first();

        if ($school === null) {
            throw ValidationException::withMessages([
                'college_list_school_id' => 'School not found on your list.',
            ]);
        }

        if (config('essay_suite.fake')) {
            $payload = $this->fakePayload($school);
            $err = $this->validateWhyPayload($payload);
            if ($err !== null) {
                throw new \RuntimeException('Fake why-school payload failed self-check: '.$err);
            }

            return $this->persist($user, $school->id, $payload);
        }

        $envelope = [
            'school' => $this->schoolBlock($school),
            'student_profile' => EssayStudentContext::compact($user),
            'student_interests' => $this->interestsLine($user),
        ];
        $prompt = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $first = $this->invokeAgent($prompt);
        $v1 = $this->validateWhyPayload($first);
        if ($v1 === null) {
            return $this->persist($user, $school->id, $first);
        }

        Log::warning('WhyThisSchoolService: first payload failed validation, retrying once.', [
            'reason' => $v1,
        ]);

        $retryPayload = json_encode([
            '_why_school_retry' => true,
            '_validation_error' => $v1,
            'why_school_request' => json_decode($prompt, true) ?: [],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $second = $this->invokeAgent($retryPayload);
        $v2 = $this->validateWhyPayload($second);
        if ($v2 !== null) {
            throw ValidationException::withMessages([
                'analysis' => 'Why-this-school draft could not be validated. Try again.',
            ]);
        }

        return $this->persist($user, $school->id, $second);
    }

    /**
     * @return array<string, mixed>
     */
    private function schoolBlock(CollegeListSchool $school): array
    {
        $deadlines = [];
        if ($school->ea_deadline) {
            $deadlines[] = 'EA: '.$school->ea_deadline->format('M j, Y');
        }
        if ($school->ed_deadline) {
            $deadlines[] = 'ED: '.$school->ed_deadline->format('M j, Y');
        }
        if ($school->rd_deadline) {
            $deadlines[] = 'RD: '.$school->rd_deadline->format('M j, Y');
        }

        return [
            'id' => (string) $school->id,
            'school_name' => $school->school_name,
            'tier' => $school->tier,
            'why_blurb' => $school->why_blurb,
            'acceptance_rate' => $school->acceptance_rate !== null ? (string) $school->acceptance_rate : null,
            'median_gpa' => $school->median_gpa !== null ? (string) $school->median_gpa : null,
            'median_sat' => $school->median_sat,
            'median_act' => $school->median_act,
            'notable_programs' => is_array($school->notable_programs) ? $school->notable_programs : [],
            'campus_size' => $school->campus_size,
            'location_city' => $school->location_city,
            'location_state' => $school->location_state,
            'deadlines' => $deadlines !== [] ? implode(' · ', $deadlines) : 'Not set',
            'application_portal_url' => $school->application_portal_url,
            'aid_estimate_band' => $school->aid_estimate_band,
            'fit_score' => $school->fit_score,
        ];
    }

    private function interestsLine(User $user): string
    {
        $user->loadMissing('onboardingProfile');
        $ctx = $user->onboardingProfile?->ai_context;
        if (! is_array($ctx)) {
            return '';
        }
        $goals = $ctx['goals_summary'] ?? $ctx['academic_interests'] ?? '';

        return is_string($goals) ? trim($goals) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeAgent(string $prompt): array
    {
        /** @var Promptable&object $agent */
        $agent = WhyThisSchoolAgent::make();
        $response = $agent->prompt($prompt);

        return StructuredAgentResponseMapper::toArray($response);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function validateWhyPayload(array $p): ?string
    {
        if (! isset($p['draft']) || ! is_string($p['draft']) || trim($p['draft']) === '') {
            return 'Missing draft.';
        }
        $words = str_word_count(strip_tags($p['draft']));
        if ($words < 100 || $words > 500) {
            return 'Draft word count out of range ('.$words.').';
        }
        if (! isset($p['hooks_used']) || ! is_array($p['hooks_used']) || $p['hooks_used'] === []) {
            return 'hooks_used must be non-empty.';
        }
        foreach ($p['hooks_used'] as $i => $h) {
            if (! is_string($h) || trim($h) === '') {
                return 'Invalid hooks_used entry '.$i.'.';
            }
        }
        if (! isset($p['generic_phrases_avoided']) || ! is_array($p['generic_phrases_avoided'])) {
            return 'Missing generic_phrases_avoided.';
        }
        if (! isset($p['suggested_improvements']) || ! is_array($p['suggested_improvements']) || count($p['suggested_improvements']) !== 3) {
            return 'suggested_improvements must have exactly 3 items.';
        }
        foreach ($p['suggested_improvements'] as $j => $s) {
            if (! is_string($s) || trim($s) === '') {
                return 'Invalid suggested_improvements '.$j.'.';
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fakePayload(CollegeListSchool $school): array
    {
        $name = $school->school_name;
        $prefix = "Opening at {$name} anchors in list-backed program cues, not brochure slogans. ";
        $draft = $prefix.trim(str_repeat(
            'Tie one measurable project to a verifiable office or tradition; keep verbs active and avoid invented faculty names. ',
            14,
        ));

        return [
            'draft' => $draft,
            'hooks_used' => [
                'Notable programs array from list row',
                'Deadline rhythm for this application cycle',
                "{$name} location context",
            ],
            'generic_phrases_avoided' => [
                'world-class faculty (no named claim)',
                'cutting-edge research (no unsourced lab name)',
            ],
            'suggested_improvements' => [
                'Name one professor paper or course catalog line you actually read.',
                'Add a dated visit, tour, or virtual session detail only you could supply.',
                'Swap one abstract value statement for a 20-word scene with dialogue or motion.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, payload: array<string, mixed>}
     */
    private function persist(User $user, string $schoolId, array $payload): array
    {
        $row = WhyThisSchoolDraft::query()->create([
            'user_id' => $user->id,
            'college_list_school_id' => $schoolId,
            'payload' => $payload,
        ]);

        return [
            'id' => (string) $row->id,
            'payload' => $payload,
        ];
    }
}
