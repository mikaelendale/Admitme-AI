<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    public function view(User $user, Document $document): Response
    {
        return $this->owns($user, $document);
    }

    public function update(User $user, Document $document): Response
    {
        return $this->owns($user, $document);
    }

    private function owns(User $user, Document $document): Response
    {
        return (int) $document->user_id === (int) $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
