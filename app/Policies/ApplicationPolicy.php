<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApplicationPolicy
{
    public function view(User $user, Application $application): Response
    {
        return $this->owns($user, $application);
    }

    public function update(User $user, Application $application): Response
    {
        return $this->owns($user, $application);
    }

    private function owns(User $user, Application $application): Response
    {
        return (int) $application->user_id === (int) $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
