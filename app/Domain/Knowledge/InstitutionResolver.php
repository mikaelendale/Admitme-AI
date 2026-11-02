<?php

namespace App\Domain\Knowledge;

use App\Models\CollegeListSchool;
use App\Models\Institution;

/**
 * Links a student's college choice to the shared institution record its knowledge is filed under.
 */
final class InstitutionResolver
{
    public function forSchool(CollegeListSchool $school): Institution
    {
        return Institution::query()->firstOrCreate(
            ['slug' => $school->school_slug],
            ['name' => $school->school_name],
        );
    }

    public function findForSchool(CollegeListSchool $school): ?Institution
    {
        return Institution::query()->where('slug', $school->school_slug)->first();
    }
}
