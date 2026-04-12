<?php

use App\Http\Controllers\AdmitMeGeneralChatController;
use App\Http\Controllers\CollegeListCouncilRunController;
use App\Http\Controllers\CollegeListsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EssayRoastController;
use App\Http\Controllers\EssayRunController;
use App\Http\Controllers\EssayStrengthScoreController;
use App\Http\Controllers\EssaySuiteController;
use App\Http\Controllers\GapFixController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\NotificationPreferencesController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileCompletionController;
use App\Http\Controllers\ProfileOverviewController;
use App\Http\Controllers\SupplementalEssayController;
use App\Http\Controllers\WhyThisSchoolController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('legal/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('legal/terms', [LegalController::class, 'terms'])->name('legal.terms');

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('onboarding/admissions-read', [OnboardingController::class, 'admissionsRead'])
        ->name('onboarding.admissions-read');
    Route::post('onboarding/step', [OnboardingController::class, 'saveStep'])->name('onboarding.step');
    Route::post('onboarding/checkout', [OnboardingController::class, 'checkout'])->name('onboarding.checkout');
    Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
});

Route::middleware(['auth', 'onboarded', 'verified', 'platform.access'])->group(function () {
    Route::get('dashboard/chats', [DashboardController::class, 'chatsIndex'])->name('dashboard.chats.index');
    Route::get('dashboard/chat/{conversation}', [DashboardController::class, 'chat'])
        ->name('dashboard.chat.show')
        ->whereUuid('conversation');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('college-lists', [CollegeListsController::class, 'index'])->name('college-lists');
    Route::get('college-lists/{collegeListSchool}', [CollegeListsController::class, 'show'])
        ->name('college-lists.school');
    Route::post('college-lists/council-runs', [CollegeListCouncilRunController::class, 'store'])
        ->name('college-lists.council-runs.store');
    Route::get('college-lists/council-runs/{run}', [CollegeListCouncilRunController::class, 'show'])
        ->name('college-lists.council-runs.show');
    Route::post('college-lists/council-runs/{run}/preflight-reply', [CollegeListCouncilRunController::class, 'preflightReply'])
        ->name('college-lists.council-runs.preflight-reply');
    Route::get('essay-suite', EssaySuiteController::class)->name('essay-suite');
    /** Essay suite supplements (no LLM) */
    Route::get('essay-suite/supplements', [SupplementalEssayController::class, 'index'])
        ->name('essay-suite.supplements.index');
    Route::post('essay-suite/supplements', [SupplementalEssayController::class, 'store'])
        ->name('essay-suite.supplements.store');
    Route::patch('essay-suite/supplements/{supplement}', [SupplementalEssayController::class, 'update'])
        ->name('essay-suite.supplements.update');
    Route::delete('essay-suite/supplements/{supplement}', [SupplementalEssayController::class, 'destroy'])
        ->name('essay-suite.supplements.destroy');
    /** Essay suite LLM — throttles: AppServiceProvider + config/essay_suite.php */
    Route::post('essay-suite/roast', [EssayRoastController::class, 'store'])
        ->middleware('throttle:essay-roast')
        ->name('essay-suite.roast.store');
    Route::post('essay-suite/why-this-school', [WhyThisSchoolController::class, 'store'])
        ->middleware('throttle:essay-why-school')
        ->name('essay-suite.why-this-school.store');
    Route::post('essay-suite/score-only', [EssayStrengthScoreController::class, 'store'])
        ->middleware('throttle:essay-score-only')
        ->name('essay-suite.score-only.store');
    /** Gap + Fix: strict per-user throttles — see RateLimiter::for in AppServiceProvider and config/gap_fix.php */
    Route::get('gap-fix', GapFixController::class)->name('gap-fix');
    Route::post('gap-fix/generate', [GapFixController::class, 'generate'])
        ->middleware('throttle:gap-fix-generate')
        ->name('gap-fix.generate');
    Route::post('gap-fix/what-if/explain', [GapFixController::class, 'whatIfExplain'])
        ->middleware('throttle:gap-fix-what-if-explain')
        ->name('gap-fix.what-if-explain');
    Route::post('essay-suite/runs', [EssayRunController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('essay-suite.runs.store');
    Route::get('essay-suite/runs/{run}', [EssayRunController::class, 'show'])
        ->name('essay-suite.runs.show')
        ->whereUlid('run');
    Route::get('profile-overview', ProfileOverviewController::class)
        ->name('profile-overview');
    Route::post('profile-completion/dump', [ProfileCompletionController::class, 'storeDump'])
        ->name('profile-completion.dump');
    Route::post('profile-completion/resume', [ProfileCompletionController::class, 'storeResume'])
        ->name('profile-completion.resume');
    Route::post('profile-completion/clarify', [ProfileCompletionController::class, 'updateClarify'])
        ->name('profile-completion.clarify');
    Route::post('profile-completion/goals', [ProfileCompletionController::class, 'updateGoals'])
        ->name('profile-completion.goals');
    Route::post('profile-completion/review', [ProfileCompletionController::class, 'updateReview'])
        ->name('profile-completion.review');
    Route::post('profile-completion/mentor', [ProfileCompletionController::class, 'updateMentor'])
        ->name('profile-completion.mentor');
    Route::post('profile-completion/refresh-insights', [ProfileCompletionController::class, 'refreshInsights'])
        ->name('profile-completion.refresh-insights');
    Route::post('dashboard/chat', AdmitMeGeneralChatController::class)
        ->middleware('throttle:30,1')
        ->name('dashboard.chat');
    Route::get('notifications', [NotificationsController::class, 'index'])
        ->name('notifications.index');
    Route::post('notifications/read-all', [NotificationsController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationsController::class, 'markRead'])
        ->name('notifications.read');
    Route::patch('settings/notification-preferences', [NotificationPreferencesController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('settings.notification-preferences.update');
});

require __DIR__.'/settings.php';
