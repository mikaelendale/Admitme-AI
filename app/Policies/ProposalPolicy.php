<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProposalPolicy
{
    public function view(User $user, Proposal $proposal): Response
    {
        return $this->owns($user, $proposal);
    }

    public function decide(User $user, Proposal $proposal): Response
    {
        return $this->owns($user, $proposal);
    }

    private function owns(User $user, Proposal $proposal): Response
    {
        return (int) $proposal->user_id === (int) $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
