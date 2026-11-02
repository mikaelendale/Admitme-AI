<?php

use App\Models\Document;
use App\Models\OnboardingProfile;
use App\Models\OnboardingSession;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Synthetic accounts for the UI tour: a student who has not started onboarding yet.
// Usage: php ui-fixtures.php <app_dir>   (environment from env.sh must point at the e2e database)
// Re-running resets that student back to "not onboarded" so the onboarding screens can be captured again.

$root = $argv[1];

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.sqlite.database');
if (! str_ends_with($database, 'e2e.sqlite')) {
    fwrite(STDERR, "Refusing to write fixtures into {$database}\n");
    exit(1);
}

$fresh = User::query()->firstOrCreate(
    ['email' => 'fresh.student@example.test'],
    User::factory()->raw(['name' => 'Ada Synthetic', 'email' => 'fresh.student@example.test']),
);
$fresh->forceFill([
    'onboarded' => false,
    'email_verified_at' => now(),
    'platform_pass_plan' => 'sprint',
    'platform_pass_ends_at' => now()->addDays(90),
])->save();

OnboardingSession::query()->where('user_id', $fresh->id)->delete();
// Profiles soft-delete; remove the row for real so onboarding starts from nothing.
OnboardingProfile::withTrashed()->where('user_id', $fresh->id)->forceDelete();
StudentFact::query()->where('user_id', $fresh->id)->delete();
foreach (Document::query()->where('user_id', $fresh->id)->get() as $document) {
    $document->forceFill(['current_version_id' => null])->save();
    $document->versions()->delete();
    $document->delete();
}

echo "ui fixtures: fresh.student@example.test (not onboarded)\n";
