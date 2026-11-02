<?php

namespace Database\Seeders;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Applications\CollegeListService;
use App\Domain\Shared\ChangeSource;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Services\Profile\ProfileCompletenessCalculator;
use App\Services\Profile\ProfileModesResolver;
use Illuminate\Database\Seeder;

/**
 * A synthetic international student for local walkthroughs:
 *   php artisan db:seed --class=AdmitMeSyntheticStudentSeeder
 *
 * Sign in as synthetic.student@example.test / password. All data is invented.
 */
class AdmitMeSyntheticStudentSeeder extends Seeder
{
    public function run(CollegeListService $colleges, ProfileCompletenessCalculator $completeness, ProfileModesResolver $modes): void
    {
        $student = User::query()->firstOrCreate(
            ['email' => 'synthetic.student@example.test'],
            User::factory()->raw(['name' => 'Ada Synthetic', 'email' => 'synthetic.student@example.test']),
        );

        // A synthetic "sprint" pass so local walkthroughs see the app the way a paying student does
        // (the UI keeps paywalls even when billing enforcement is off).
        $student->forceFill(['platform_pass_plan' => 'sprint', 'platform_pass_ends_at' => now()->addDays(90)])->save();

        if (! OnboardingProfile::query()->where('user_id', $student->getKey())->exists()) {
            $profile = OnboardingProfile::factory()->internationalALevel()->for($student)->create();

            // Factories skip the derived fields the app normally computes on save...
            $calculated = $completeness->calculate($profile);
            $profile->forceFill([
                'profile_completeness_score' => $calculated['score'],
                'completeness_callouts_json' => $calculated['callouts'],
                'profile_modes_json' => $modes->resolve($profile),
            ])->save();
        }

        $choices = [
            ['Synthetic Lakeside University', 'reach', CollegeChoiceStatus::Shortlisted],
            ['Synthetic Northfield College', 'match', CollegeChoiceStatus::Shortlisted],
            ['Synthetic Riverbend Institute', 'safety', CollegeChoiceStatus::Shortlisted],
            ['Synthetic Harbor State University', 'match', CollegeChoiceStatus::Applying],
        ];

        foreach ($choices as [$name, $tier, $status]) {
            if ($student->collegeListSchools()->where('school_name', $name)->exists()) {
                continue;
            }

            $school = CollegeListSchool::factory()->for($student)->create([
                'school_name' => $name,
                'school_slug' => str($name)->slug()->toString(),
                'tier' => $tier,
                'rd_deadline' => now()->addMonths(3)->toDateString(),
                'admissions_cycle_label' => 'Fall 2027 entry',
            ]);

            if ($status === CollegeChoiceStatus::Applying) {
                $colleges->update($school, ['choice_status' => $status->value], null, ChangeSource::system('seeder'));
            }
        }
    }
}
