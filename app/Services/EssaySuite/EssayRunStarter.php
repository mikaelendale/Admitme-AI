<?php

namespace App\Services\EssaySuite;

use App\Jobs\ProcessEssayRunJob;
use App\Models\CollegeListSchool;
use App\Models\EssayRun;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class EssayRunStarter
{
    /**
     * @param  array{
     *     essay_type: string,
     *     essay_prompt: string,
     *     word_limit?: int|null,
     *     tone?: string|null
     * }  $essayOptions
     */
    public function start(User $user, string $collegeListSchoolId, string $runType, string $message, array $essayOptions = []): EssayRun
    {
        $message = trim($message);

        if (! in_array($runType, [EssayRun::TYPE_BRAINSTORM, EssayRun::TYPE_DRAFT], true)) {
            throw new \InvalidArgumentException('Invalid run type.');
        }

        $essayPrompt = trim((string) ($essayOptions['essay_prompt'] ?? ''));
        if ($essayPrompt === '') {
            throw new \InvalidArgumentException('Essay prompt is required.');
        }

        $essayType = (string) ($essayOptions['essay_type'] ?? EssayRun::ESSAY_TYPE_SUPPLEMENTAL);
        if (! in_array($essayType, EssayRun::essayTypes(), true)) {
            throw new \InvalidArgumentException('Invalid essay type.');
        }

        if ($runType === EssayRun::TYPE_DRAFT && $message === '') {
            throw new \InvalidArgumentException('Draft runs require seed text (outline, thesis, or paragraph) in the message field.');
        }

        $tone = $essayOptions['tone'] ?? null;
        if (! is_string($tone) || $tone === '') {
            $prefs = is_array($user->ai_preferences) ? $user->ai_preferences : [];
            $tone = is_string($prefs['mentor_tone'] ?? null) ? $prefs['mentor_tone'] : 'balanced';
        }

        $wordLimit = $essayOptions['word_limit'] ?? null;
        if ($wordLimit !== null) {
            $wordLimit = (int) $wordLimit;
            if ($wordLimit < 1) {
                $wordLimit = null;
            }
        }

        $school = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->where('is_removed', false)
            ->where('id', $collegeListSchoolId)
            ->firstOrFail();

        $hasActive = EssayRun::query()
            ->where('user_id', $user->id)
            ->whereIn('status', EssayRun::activeStatuses())
            ->exists();

        if ($hasActive) {
            throw new ConflictHttpException('An essay job is already running. Wait for it to finish.');
        }

        $userPromptStored = $message !== '' ? $message : ($runType === EssayRun::TYPE_BRAINSTORM
            ? '(No extra focus — use profile + prompt.)'
            : '');

        $run = EssayRun::query()->create([
            'user_id' => $user->id,
            'college_list_school_id' => $school->id,
            'run_type' => $runType,
            'status' => EssayRun::STATUS_QUEUED,
            'user_prompt' => $userPromptStored,
            'meta' => [
                'essay_type' => $essayType,
                'essay_prompt' => $essayPrompt,
                'word_limit' => $wordLimit,
                'tone' => $tone,
            ],
        ]);

        ProcessEssayRunJob::dispatch((string) $run->id);

        return $run->fresh();
    }
}
