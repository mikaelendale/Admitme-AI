<?php

use App\Domain\Approvals\ProposalService;
use App\Models\CollegeListRun;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Services\CollegeList\CollegeListAssemblyService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Extra synthetic fixtures for the e2e run: pending proposals for the synthetic student.
// Usage: php fixtures.php <app_dir>   (environment from env.sh must point at the e2e database)

$root = $argv[1];

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.sqlite.database');
if (! str_ends_with($database, 'e2e.sqlite')) {
    fwrite(STDERR, "Refusing to write fixtures into {$database}\n");
    exit(1);
}

$student = User::query()->where('email', 'synthetic.student@example.test')->firstOrFail();
$proposals = app(ProposalService::class);

$proposals->proposeProfileChanges($student, [
    'graduation_year' => ['value' => 2028, 'reason' => 'Student said they are taking a gap year and start in 2028.'],
    'budget_band' => ['value' => 'under_25k_usd', 'reason' => 'Student said their family can cover about $25k a year.'],
], 'e2e-fixture');

$proposals->proposeCollegeAdditions($student, [
    ['school_name' => 'Synthetic Willow University', 'tier' => 'match', 'reason' => 'Strong economics, generous aid for internationals.'],
    ['school_name' => 'Synthetic Aspen College', 'tier' => 'safety', 'reason' => 'Within budget.'],
    ['school_name' => 'Synthetic Birch Institute', 'tier' => 'reach', 'reason' => 'Top maths department.'],
], 'e2e-fixture');

echo "fixtures: 2 pending proposals\n";

// Phase 5 (D-016): an operator verifies the synthetic college's site, so pages on it are official/shared.
// The fixture site answers on 127.0.0.1 (verified) and localhost (an "unofficial" third-party host).
Artisan::call('admitme:institution-domain', [
    'institution' => 'synthetic-lakeside-university',
    'domain' => '127.0.0.1',
    '--name' => 'Synthetic Lakeside University',
]);
echo trim(Artisan::output())."\n";

// A second synthetic student with the same college, to check that private sources stay private.
$peer = User::query()->firstOrCreate(
    ['email' => 'synthetic.peer@example.test'],
    User::factory()->raw(['name' => 'Ben Synthetic', 'email' => 'synthetic.peer@example.test']),
);
$peer->forceFill(['platform_pass_plan' => 'sprint', 'platform_pass_ends_at' => now()->addDays(90)])->save();
OnboardingProfile::factory()->internationalALevel()->for($peer)->create();
CollegeListSchool::factory()->for($peer)->create([
    'school_name' => 'Synthetic Lakeside University',
    'school_slug' => 'synthetic-lakeside-university',
    'tier' => 'match',
    'admissions_cycle_label' => 'Fall 2027 entry',
]);
echo "fixtures: peer student synthetic.peer@example.test\n";

// Phase 6: a budget for the synthetic student and a completed research run with three suggestions,
// written through the same assembly code the list council uses.
$student->onboardingProfile()->firstOrFail()->forceFill(['budget_amount' => 25000, 'budget_currency' => 'USD', 'target_intake' => 'Fall 2027 entry'])->save();
$run = CollegeListRun::query()->create([
    'user_id' => $student->id,
    'status' => CollegeListRun::STATUS_COMPLETED,
    'intent' => 'refine',
    'request_message' => 'Economics, need-based aid, US or UK',
    'summary_text' => 'Three more colleges with strong economics and aid for international students.',
    'started_at' => now()->subMinutes(10),
    'completed_at' => now()->subMinutes(5),
]);
$assembly = app(CollegeListAssemblyService::class);
foreach ([
    ['school_name' => 'Synthetic Cedar College', 'tier' => 'match', 'fit_score' => 78, 'why_blurb' => 'Small economics department with close advising.', 'notable_programs' => ['Economics', 'Statistics'], 'location_state' => 'VT', 'aid_estimate_band' => 'strong', 'median_gpa' => 3.7],
    ['school_name' => 'Synthetic Maple Institute', 'tier' => 'safety', 'fit_score' => 70, 'why_blurb' => 'Applied mathematics with co-op placements.', 'notable_programs' => ['Applied Mathematics'], 'location_state' => 'OH', 'aid_estimate_band' => 'moderate', 'median_gpa' => 3.4],
    ['school_name' => 'Synthetic Summit University', 'tier' => 'reach', 'fit_score' => 66, 'why_blurb' => 'Research-heavy economics; very selective.', 'notable_programs' => ['Economics', 'Public Policy'], 'location_state' => 'CA', 'aid_estimate_band' => 'limited', 'median_gpa' => 3.95],
] as $row) {
    $assembly->upsertSchoolRow($student, $row, now(), (string) $run->id);
}
echo "fixtures: research run with 3 suggestions, budget USD 25,000\n";

// Phase 7: the synthetic Cedar College has an operator-verified site (the fixture host) with a cost page.
Artisan::call('admitme:institution-domain', [
    'institution' => 'synthetic-cedar-college',
    'domain' => '127.0.0.1',
    '--name' => 'Synthetic Cedar College',
]);
echo trim(Artisan::output())."\n";

// Phase 8: Synthetic Northfield College asks for no AI help with application writing (operator-recorded).
Artisan::call('admitme:institution-domain', ['institution' => 'synthetic-northfield-college', '--name' => 'Synthetic Northfield College']);
Artisan::call('admitme:institution-writing-policy', ['institution' => 'synthetic-northfield-college', 'policy' => 'no_ai_assistance']);
echo trim(Artisan::output())."\n";
