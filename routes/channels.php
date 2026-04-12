<?php

use App\Models\CollegeListRun;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('college-list-runs.{runId}', function ($user, string $runId) {
    $run = CollegeListRun::query()->find($runId);

    return $run !== null && (int) $run->user_id === (int) $user->id;
});
