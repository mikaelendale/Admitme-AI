<?php

namespace App\Services\CollegeListCouncil;

use App\Models\CollegeListRun;
use App\Models\User;

interface CollegeListCouncilOrchestratorContract
{
    public function execute(CollegeListRun $run, User $user): void;
}
