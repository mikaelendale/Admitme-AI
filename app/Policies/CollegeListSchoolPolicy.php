<?php

namespace App\Policies;

use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * College choices are visible only to the student who owns them. Archived choices are hidden from
 * direct access the same way the screens hide them; non-owners get a 404 so ids reveal nothing.
 */
class CollegeListSchoolPolicy
{
    public function view(User $user, CollegeListSchool $school): Response
    {
        return $this->ownsActive($user, $school);
    }

    public function update(User $user, CollegeListSchool $school): Response
    {
        return $this->ownsActive($user, $school);
    }

    public function delete(User $user, CollegeListSchool $school): Response
    {
        return $this->ownsActive($user, $school);
    }

    private function ownsActive(User $user, CollegeListSchool $school): Response
    {
        return (int) $school->user_id === (int) $user->getKey() && ! $school->is_removed
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
