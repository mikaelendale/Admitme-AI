<?php

use App\Http\Controllers\ActiveJobsController;
use App\Http\Controllers\AdmitMeGeneralChatController;
use App\Http\Controllers\AgentTasksController;
use App\Http\Controllers\ApplicationsController;
use App\Http\Controllers\ApprovalsController;
use App\Http\Controllers\BrowserExtensionSettingsController;
use App\Http\Controllers\CollegeKnowledgeController;
use App\Http\Controllers\CollegeListCouncilRunController;
use App\Http\Controllers\CollegeListsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataExportsController;
use App\Http\Controllers\DeadlinesController;
use App\Http\Controllers\DocumentsController;
use App\Http\Controllers\DocumentValuesController;
use App\Http\Controllers\EssayRoastController;
use App\Http\Controllers\EssayRunController;
use App\Http\Controllers\EssayStrengthScoreController;
use App\Http\Controllers\EssaySuiteController;
use App\Http\Controllers\GapFixController;
use App\Http\Controllers\JobRetryController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MentorTurnsController;
use App\Http\Controllers\NotificationPreferencesController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileCompletionController;
use App\Http\Controllers\ProfileOverviewController;
use App\Http\Controllers\ProfileSetupController;
use App\Http\Controllers\ProviderCallbackController;
use App\Http\Controllers\ProviderRedirectController;
use App\Http\Controllers\SupplementalEssayController;
use App\Http\Controllers\TasksController;
use App\Http\Controllers\WhyThisSchoolController;
use App\Http\Controllers\WritingController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('legal/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('legal/terms', [LegalController::class, 'terms'])->name('legal.terms');

Route::get('/auth/{provider}/redirect', ProviderRedirectController::class)
    ->name('auth.redirect')
    ->middleware(['throttle:5,1']);

Route::get('/auth/{provider}/callback', ProviderCallbackController::class)
    ->name('auth.callback')
    ->middleware(['throttle:5,1']);

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // First-run onboarding (Phase 4 order): consent → situation → curriculum → interests → destinations →
    // budget & aid → optional documents → review with targeted questions → (plan, only when billing applies).
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('onboarding/consent', [OnboardingController::class, 'consent'])->middleware('throttle:30,1')->name('onboarding.consent');
    Route::post('onboarding/sections/{section}', [OnboardingController::class, 'saveSection'])
        ->middleware('throttle:60,1')
        ->whereAlpha('section')
        ->name('onboarding.sections.save');
    Route::post('onboarding/answers', [OnboardingController::class, 'saveAnswers'])->middleware('throttle:60,1')->name('onboarding.answers');
    Route::post('onboarding/documents', [OnboardingController::class, 'uploadDocument'])->middleware('throttle:20,1')->name('onboarding.documents.store');
    Route::post('onboarding/checkout', [OnboardingController::class, 'checkout'])->name('onboarding.checkout');
    Route::post('onboarding/skip-billing', [OnboardingController::class, 'skipBilling'])->name('onboarding.skip-billing');
    Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
});

Route::middleware(['auth', 'onboarded', 'verified', 'platform.access'])->group(function () {
    Route::get('dashboard/chats', [DashboardController::class, 'chatsIndex'])->name('dashboard.chats.index');
    Route::get('dashboard/chats/recent', [DashboardController::class, 'recentChats'])->name('dashboard.chats.recent');
    Route::get('dashboard/chat/{conversation}/messages', [DashboardController::class, 'chatMessages'])
        ->whereUuid('conversation')
        ->name('dashboard.chat.messages');
    Route::get('dashboard/chat/{conversation}', [DashboardController::class, 'chat'])
        ->name('dashboard.chat.show')
        ->whereUuid('conversation');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('college-lists', [CollegeListsController::class, 'index'])->name('college-lists');
    Route::post('college-lists/schools/manual', [CollegeListsController::class, 'storeManual'])
        ->name('college-lists.schools.manual');
    Route::patch('college-lists/{collegeListSchool}', [CollegeListsController::class, 'update'])
        ->name('college-lists.school.update');
    Route::delete('college-lists/{collegeListSchool}', [CollegeListsController::class, 'destroy'])
        ->name('college-lists.school.destroy');
    Route::get('college-lists/{collegeListSchool}/knowledge', [CollegeKnowledgeController::class, 'show'])
        ->name('college-lists.knowledge');
    Route::post('college-lists/{collegeListSchool}/sources', [CollegeKnowledgeController::class, 'addSource'])
        ->middleware('throttle:20,1')
        ->name('college-lists.sources.store');
    Route::post('college-lists/{collegeListSchool}/research', [CollegeKnowledgeController::class, 'ask'])
        ->middleware('throttle:20,1')
        ->name('college-lists.research');
    Route::post('college-lists/suggestions/review', [CollegeListsController::class, 'reviewSuggestions'])
        ->middleware('throttle:30,1')
        ->name('college-lists.suggestions.review');
    Route::get('college-lists/compare', [CollegeListsController::class, 'compare'])
        ->name('college-lists.compare');
    Route::get('college-lists/{collegeListSchool}', [CollegeListsController::class, 'show'])
        ->name('college-lists.school');
    Route::post('college-lists/council-runs', [CollegeListCouncilRunController::class, 'store'])
        ->middleware(['throttle:10,1', 'quota:college_list'])
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
    /** Essay suite LLM — DDoS throttle + plan quotas (config/feature_limits.php) */
    Route::post('essay-suite/roast', [EssayRoastController::class, 'store'])
        ->middleware(['throttle:30,1', 'quota:essay_roast'])
        ->name('essay-suite.roast.store');
    Route::post('essay-suite/why-this-school', [WhyThisSchoolController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('essay-suite.why-this-school.store');
    Route::post('essay-suite/score-only', [EssayStrengthScoreController::class, 'store'])
        ->middleware(['throttle:30,1', 'quota:cliche_detector'])
        ->name('essay-suite.score-only.store');
    /** Gap + Fix: DDoS throttle + plan quotas — see config/feature_limits.php, FeatureQuotaManager */
    Route::get('gap-fix', GapFixController::class)->name('gap-fix');
    Route::post('gap-fix/generate', [GapFixController::class, 'generate'])
        ->middleware(['throttle:30,1', 'quota:gap_analysis'])
        ->name('gap-fix.generate');
    Route::post('gap-fix/what-if/explain', [GapFixController::class, 'whatIfExplain'])
        ->middleware(['throttle:30,1', 'quota:fix_roadmap'])
        ->name('gap-fix.what-if-explain');
    Route::post('essay-suite/runs', [EssayRunController::class, 'store'])
        ->middleware(['throttle:20,1', 'quota:essay_draft'])
        ->name('essay-suite.runs.store');
    Route::get('essay-suite/runs/{run}', [EssayRunController::class, 'show'])
        ->name('essay-suite.runs.show')
        ->whereUlid('run');
    Route::get('profile-overview', ProfileOverviewController::class)
        ->name('profile-overview');
    Route::get('applications', [ApplicationsController::class, 'index'])
        ->name('applications.index');
    // The deadline tracker: every college's Early Action / Early Decision / Regular Decision dates (or rolling) as a calendar.
    Route::get('deadlines', [DeadlinesController::class, 'index'])
        ->name('deadlines.index');
    Route::post('applications', [ApplicationsController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('applications.store');
    Route::patch('applications/{application}', [ApplicationsController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('applications.update')
        ->whereUlid('application');
    Route::get('applications/{application}', [ApplicationsController::class, 'show'])
        ->name('applications.show')
        ->whereUlid('application');
    Route::post('applications/{application}/checklist', [ApplicationsController::class, 'rebuildChecklist'])
        ->middleware('throttle:30,1')
        ->name('applications.checklist.rebuild')
        ->whereUlid('application');
    Route::post('applications/{application}/items', [ApplicationsController::class, 'storeItem'])
        ->middleware('throttle:60,1')
        ->name('applications.items.store')
        ->whereUlid('application');
    Route::patch('applications/{application}/items/{item}', [ApplicationsController::class, 'updateItem'])
        ->middleware('throttle:120,1')
        ->name('applications.items.update')
        ->whereUlid('application')
        ->whereUlid('item');
    Route::get('tasks', TasksController::class)
        ->name('tasks.index');
    Route::get('settings/browser-extension', [BrowserExtensionSettingsController::class, 'show'])->name('browser-extension.show');
    Route::post('settings/browser-extension/codes', [BrowserExtensionSettingsController::class, 'createCode'])->middleware('throttle:10,1')->name('browser-extension.codes.store');
    Route::delete('settings/browser-extension/devices/{token}', [BrowserExtensionSettingsController::class, 'revoke'])->name('browser-extension.devices.revoke');
    Route::delete('settings/browser-extension/control', [BrowserExtensionSettingsController::class, 'stopControl'])->name('browser-extension.control.stop');
    Route::post('applications/{application}/browser-fill', [BrowserExtensionSettingsController::class, 'prepareFill'])->middleware('throttle:20,1')->name('applications.browser-fill')->whereUlid('application');
    Route::get('exports', [DataExportsController::class, 'index'])->name('exports.index');
    Route::post('exports', [DataExportsController::class, 'store'])->middleware('throttle:exports')->name('exports.store');
    Route::get('exports/{export}/download', [DataExportsController::class, 'download'])
        ->middleware(['signed', 'throttle:export-downloads'])
        ->name('exports.download')
        ->whereUlid('export');
    Route::delete('exports/{export}', [DataExportsController::class, 'destroy'])->name('exports.destroy')->whereUlid('export');
    Route::delete('documents/{document}', [DataExportsController::class, 'destroyDocument'])->middleware('throttle:30,1')->name('documents.destroy')->whereUlid('document');
    Route::delete('writing/{piece}', [DataExportsController::class, 'destroyWriting'])->middleware('throttle:30,1')->name('writing.destroy')->whereUlid('piece');
    Route::delete('knowledge-sources/{source}', [DataExportsController::class, 'destroySource'])->middleware('throttle:30,1')->name('knowledge-sources.destroy')->whereUlid('source');
    Route::get('writing', [WritingController::class, 'index'])->name('writing.index');
    Route::post('writing', [WritingController::class, 'store'])->middleware('throttle:30,1')->name('writing.store');
    Route::get('writing/{piece}', [WritingController::class, 'show'])->name('writing.show')->whereUlid('piece');
    Route::post('writing/{piece}/versions', [WritingController::class, 'saveVersion'])->middleware('throttle:120,1')->name('writing.versions.store')->whereUlid('piece');
    Route::post('writing/{piece}/feedback', [WritingController::class, 'feedback'])->middleware(['throttle:20,1'])->name('writing.feedback')->whereUlid('piece');
    Route::post('applications/{application}/items/{item}/writing', [WritingController::class, 'fromEssayStep'])
        ->middleware('throttle:30,1')
        ->name('applications.items.writing')
        ->whereUlid('application')
        ->whereUlid('item');
    Route::post('applications/{application}/form-plan', [ApplicationsController::class, 'formPlan'])
        ->middleware('throttle:10,1')
        ->name('applications.form-plan')
        ->whereUlid('application');
    Route::post('agent-tasks/playbooks/{playbook}', [AgentTasksController::class, 'startPlaybook'])
        ->middleware('throttle:10,1')
        ->name('agent-tasks.playbooks.start');
    Route::get('agent-tasks/{task}', [AgentTasksController::class, 'show'])
        ->name('agent-tasks.show')
        ->whereUlid('task');
    foreach (['cancel', 'pause', 'resume', 'retry'] as $action) {
        Route::post("agent-tasks/{task}/{$action}", [AgentTasksController::class, $action])
            ->middleware('throttle:60,1')
            ->name("agent-tasks.{$action}")
            ->whereUlid('task');
    }
    Route::get('approvals', [ApprovalsController::class, 'index'])
        ->name('approvals.index');
    Route::get('approvals/{proposal}', [ApprovalsController::class, 'show'])
        ->name('approvals.show')
        ->whereUlid('proposal');
    Route::post('approvals/{proposal}/decision', [ApprovalsController::class, 'decide'])
        ->middleware('throttle:60,1')
        ->name('approvals.decide')
        ->whereUlid('proposal');

    Route::get('documents', [DocumentsController::class, 'index'])
        ->name('documents.index');
    Route::get('documents/attachable', [DocumentsController::class, 'attachable'])
        ->name('documents.attachable');
    Route::post('documents', [DocumentsController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('documents.store');
    Route::get('documents/{document}', [DocumentsController::class, 'show'])
        ->name('documents.show')
        ->whereUlid('document');
    Route::post('documents/{document}/versions', [DocumentsController::class, 'storeVersion'])
        ->middleware('throttle:20,1')
        ->name('documents.versions.store')
        ->whereUlid('document');
    Route::get('documents/{document}/versions/{version}/file', [DocumentsController::class, 'file'])
        ->name('documents.versions.file')
        ->whereUlid(['document', 'version']);
    Route::post('documents/{document}/versions/{version}/retry', [DocumentsController::class, 'retry'])
        ->middleware('throttle:10,1')
        ->name('documents.versions.retry')
        ->whereUlid(['document', 'version']);
    Route::post('document-values/{fact}/decision', [DocumentValuesController::class, 'decide'])
        ->middleware('throttle:60,1')
        ->name('documents.values.decide')
        ->whereNumber('fact');

    Route::get('profile-setup', [ProfileSetupController::class, 'show'])
        ->name('profile-setup');
    Route::post('profile-setup/answers', [ProfileSetupController::class, 'answers'])
        ->middleware('throttle:60,1')
        ->name('profile-setup.answers');
    Route::post('profile-setup/{section}', [ProfileSetupController::class, 'save'])
        ->middleware('throttle:60,1')
        ->name('profile-setup.save')
        ->whereAlpha('section');
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
    // Background replies: start one, then read its parts in order (a refresh picks it up again).
    Route::post('dashboard/chat/turns', [MentorTurnsController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('dashboard.chat.turns.store');
    Route::get('dashboard/chat/turns/active', [MentorTurnsController::class, 'active'])
        ->name('dashboard.chat.turns.active');
    Route::get('dashboard/chat/turns/{mentorTurn}/events', [MentorTurnsController::class, 'events'])
        ->middleware('throttle:600,1')
        ->name('dashboard.chat.turns.events');
    Route::post('dashboard/chat/turns/{mentorTurn}/cancel', [MentorTurnsController::class, 'cancel'])
        ->name('dashboard.chat.turns.cancel');
    Route::get('notifications', [NotificationsController::class, 'index'])
        ->name('notifications.index');
    Route::post('notifications/read-all', [NotificationsController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationsController::class, 'markRead'])
        ->name('notifications.read');
    Route::patch('settings/notification-preferences', [NotificationPreferencesController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('settings.notification-preferences.update');

    Route::post('jobs/{jobTrackingId}/retry', JobRetryController::class)
        ->middleware('throttle:20,1')
        ->name('jobs.retry')
        ->whereNumber('jobTrackingId');

    Route::get('api/jobs/active', ActiveJobsController::class)
        ->middleware('throttle:60,1')
        ->name('api.jobs.active');
});

require __DIR__.'/settings.php';
