<?php

namespace App\Jobs;

use App\Ai\Agents\EssayBrainstormAgent;
use App\Ai\Agents\EssayDraftCrafterAgent;
use App\Models\CollegeListSchool;
use App\Models\EssayRun;
use App\Models\User;
use App\Notifications\EssayRunCompletedNotification;
use App\Notifications\EssayRunFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class ProcessEssayRunJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $essayRunId,
    ) {}

    public function handle(): void
    {
        $run = EssayRun::query()->find($this->essayRunId);
        if ($run === null) {
            return;
        }

        if ($run->isTerminal()) {
            return;
        }

        /** @var User|null $user */
        $user = User::query()->find($run->user_id);
        if ($user === null) {
            $this->failRun($run, 'User not found.');

            return;
        }

        $school = $run->collegeListSchool;
        if ($school === null) {
            $this->failRun($run, 'School not found.');

            return;
        }

        $run->forceFill([
            'status' => EssayRun::STATUS_PROCESSING,
            'started_at' => $run->started_at ?? now(),
        ])->save();

        try {
            if (config('essay_suite.fake')) {
                $markdown = $this->fakeMarkdown($run);
            } else {
                $payload = $this->buildPromptPayload($user, $school, $run);
                $prompt = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($run->run_type === EssayRun::TYPE_BRAINSTORM) {
                    $response = EssayBrainstormAgent::make()->prompt($prompt);
                } else {
                    $response = EssayDraftCrafterAgent::make()->prompt($prompt);
                }
                $markdown = $this->extractContent($response);

                // Retry added: agent returns empty on malformed structured response
                if (trim($markdown) === '') {
                    $decoded = json_decode($prompt, true);
                    if (! is_array($decoded)) {
                        $decoded = [];
                    }
                    $retryPrompt = json_encode(
                        array_merge($decoded, [
                            '_retry' => true,
                            '_reason' => 'previous response returned empty content',
                        ]),
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    ) ?: $prompt;
                    if ($run->run_type === EssayRun::TYPE_BRAINSTORM) {
                        $response = EssayBrainstormAgent::make()->prompt($retryPrompt);
                    } else {
                        $response = EssayDraftCrafterAgent::make()->prompt($retryPrompt);
                    }
                    $markdown = $this->extractContent($response);
                    if (trim($markdown) === '') {
                        throw new \RuntimeException('Essay agent returned empty content after retry');
                    }
                }
            }

            if (trim($markdown) === '') {
                throw new \RuntimeException('Model returned empty output.');
            }

            $firstLine = (string) (Str::of($markdown)->explode("\n")->first() ?? '');
            $title = Str::of($firstLine)
                ->replaceMatches('/^#+\s*/', '')
                ->replace('**', '')
                ->squish()
                ->limit(90, '…')
                ->toString();
            if ($title === '') {
                $title = $run->run_type === EssayRun::TYPE_BRAINSTORM
                    ? 'Brainstorm — '.$school->school_name
                    : 'Draft — '.$school->school_name;
            }

            $run->forceFill([
                'status' => EssayRun::STATUS_COMPLETED,
                'output_markdown' => $markdown,
                'title_for_card' => $title,
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            try {
                $user->notify(new EssayRunCompletedNotification(
                    $run->id,
                    $run->run_type,
                    $run->collegeListSchool->school_name ?? 'your school',
                ));
            } catch (Throwable $e) {
                Log::warning('Notification failed: '.$e->getMessage(), [
                    'user_id' => $user->id,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('ProcessEssayRunJob failed', [
                'essay_run_id' => $run->id,
                'exception' => $e->getMessage(),
            ]);
            $this->failRun($run, Str::limit($e->getMessage(), 2000));
        }
    }

    private function failRun(EssayRun $run, string $message): void
    {
        $run->forceFill([
            'status' => EssayRun::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();

        $run->loadMissing('collegeListSchool');
        $failUser = User::query()->find($run->user_id);
        if ($failUser !== null) {
            try {
                $failUser->notify(new EssayRunFailedNotification(
                    $run->run_type,
                    $run->collegeListSchool->school_name ?? 'your school',
                ));
            } catch (Throwable $e) {
                Log::warning('Notification failed: '.$e->getMessage(), [
                    'user_id' => $failUser->id,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPromptPayload(User $user, CollegeListSchool $school, EssayRun $run): array
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
        $deadlineSummary = $deadlines !== [] ? implode(' · ', $deadlines) : 'Not set';

        $meta = is_array($run->meta) ? $run->meta : [];
        $essayType = (string) ($meta['essay_type'] ?? EssayRun::ESSAY_TYPE_SUPPLEMENTAL);
        if (! in_array($essayType, EssayRun::essayTypes(), true)) {
            $essayType = EssayRun::ESSAY_TYPE_SUPPLEMENTAL;
        }
        $essayPrompt = trim((string) ($meta['essay_prompt'] ?? $run->user_prompt));
        $wordLimit = isset($meta['word_limit']) && is_numeric($meta['word_limit']) ? (int) $meta['word_limit'] : null;
        $tone = is_string($meta['tone'] ?? null) && ($meta['tone'] ?? '') !== '' ? (string) $meta['tone'] : 'balanced';

        $user->loadMissing('onboardingProfile');
        $profile = $user->onboardingProfile?->ai_context;
        if (! is_array($profile)) {
            $profile = [];
        }

        $schoolBlock = [
            'name' => $school->school_name,
            'tier' => $school->tier,
            'why_blurb' => $school->why_blurb,
            'location_city' => $school->location_city,
            'location_state' => $school->location_state,
            'deadlines' => $deadlineSummary,
            'notable_programs' => is_array($school->notable_programs) ? $school->notable_programs : [],
            'campus_size' => $school->campus_size,
        ];

        $requirements = [
            'essay_type' => $essayType,
            'essay_prompt' => $essayPrompt,
            'word_limit' => $wordLimit,
            'mentor_tone' => $tone,
            'why_school_mode' => $essayType === EssayRun::ESSAY_TYPE_WHY_SCHOOL,
        ];

        $base = [
            'school' => $schoolBlock,
            'student_profile' => $profile,
            'essay_requirements' => $requirements,
        ];

        if ($run->run_type === EssayRun::TYPE_BRAINSTORM) {
            return $base + [
                'student_request' => $run->user_prompt,
            ];
        }

        return $base + [
            'seed' => $run->user_prompt,
        ];
    }

    private function extractContent(mixed $response): string
    {
        if ($response instanceof StructuredAgentResponse) {
            $structured = $response->structured;
            if (is_array($structured) && isset($structured['content']) && is_string($structured['content'])) {
                return $structured['content'];
            }
        }

        return '';
    }

    private function fakeMarkdown(EssayRun $run): string
    {
        $name = $run->collegeListSchool?->school_name ?? 'School';

        if ($run->run_type === EssayRun::TYPE_BRAINSTORM) {
            return <<<MD
### Service + STEM hook ({$name})
- Tie a robotics or coding project to a specific club or class you can verify.
- Show one metric (hours, people served) you can back up.

### Counter-narrative to “well-rounded”
- Pick one obsession; explain what you gave up to go deep.

### Place + program bridge
- Name one academic area at {$name} and why it fits your trajectory (no invented faculty).

### Failure → pivot
- One low moment that changed how you lead or listen — keep it concrete.
MD;
        }

        return <<<MD
# Draft (preview — {$name})

## Opening
This is **stub draft output** while `ESSAY_SUITE_FAKE` is enabled. Replace with your real seed and run again in production.

## Middle
Connect your spike to what you want at {$name} using only details you can verify.

## Close
End with a specific next step on campus, not a generic “ever since I was young.”
MD;
    }
}
