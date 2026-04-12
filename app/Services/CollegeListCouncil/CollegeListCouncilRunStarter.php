<?php

namespace App\Services\CollegeListCouncil;

use App\Jobs\RunCollegeListCouncilJob;
use App\Models\CollegeListRun;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollegeListCouncilRunStarter
{
    /**
     * @param  array<string, mixed>|null  $preflightPayload  When set, the job skips a second auditor call and replays preflight events from this payload.
     *
     * @throws ConflictHttpException
     */
    public function start(User $user, string $message, string $intent, ?array $preflightPayload = null): CollegeListRun
    {
        if (! Schema::hasTable('college_list_runs')) {
            throw new \RuntimeException('College list runs table is missing. Run migrations.');
        }

        $hasActive = CollegeListRun::query()
            ->where('user_id', $user->id)
            ->whereIn('status', CollegeListRun::activeStatuses())
            ->exists();

        if ($hasActive) {
            throw new ConflictHttpException('A college list build is already in progress.');
        }

        $attributes = [
            'user_id' => $user->id,
            'status' => CollegeListRun::STATUS_QUEUED,
            'intent' => $intent,
            'request_message' => $message,
        ];

        if ($preflightPayload !== null) {
            $attributes['preflight_payload'] = $preflightPayload;
        }

        $run = CollegeListRun::query()->create($attributes);

        RunCollegeListCouncilJob::dispatch((string) $run->id);

        return $run;
    }

    public function privateChannelName(CollegeListRun $run): string
    {
        return 'college-list-runs.'.(string) $run->id;
    }
}
