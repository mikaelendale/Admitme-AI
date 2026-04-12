<?php

namespace App\Providers;

use App\Ai\Storage\AdmitMeConversationStore;
use App\Listeners\LogLaravelAiSdkTelemetry;
use App\Listeners\RecordOnboardingCheckoutFromStripeWebhook;
use App\Services\Billing\ProfileResumePdfImportLimiter;
use App\Services\CollegeListCouncil\CollegeListCouncilOrchestratorContract;
use App\Services\CollegeListCouncil\FakeCollegeListCouncilOrchestrator;
use App\Services\CollegeListCouncil\RealCollegeListCouncilOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Cashier\Events\WebhookReceived;
use Smalot\PdfParser\Parser as PdfParser;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ConversationStore::class, AdmitMeConversationStore::class);

        $this->app->bind(PdfParser::class, fn () => new PdfParser);

        $this->app->singleton(ProfileResumePdfImportLimiter::class, fn (): ProfileResumePdfImportLimiter => ProfileResumePdfImportLimiter::fromConfig());

        $this->app->bind(CollegeListCouncilOrchestratorContract::class, function ($app): CollegeListCouncilOrchestratorContract {
            if (config('college_list_council.fake_phases')) {
                return $app->make(FakeCollegeListCouncilOrchestrator::class);
            }

            return $app->make(RealCollegeListCouncilOrchestrator::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('gap-fix-generate', function (Request $request): Limit {
            $uid = (string) ($request->user()?->id ?? $request->ip());

            return Limit::perHour((int) config('gap_fix.generate_per_user_per_hour', 4))->by('gap-fix-gen:'.$uid);
        });

        RateLimiter::for('gap-fix-what-if-explain', function (Request $request): Limit {
            $uid = (string) ($request->user()?->id ?? $request->ip());

            return Limit::perHour((int) config('gap_fix.what_if_explain_per_user_per_hour', 10))->by('gap-fix-wi:'.$uid);
        });

        RateLimiter::for('essay-roast', function (Request $request): Limit {
            $uid = (string) ($request->user()?->id ?? $request->ip());

            return Limit::perHour((int) config('essay_suite.roast_per_user_per_hour', 5))->by('essay-roast:'.$uid);
        });

        RateLimiter::for('essay-why-school', function (Request $request): Limit {
            $uid = (string) ($request->user()?->id ?? $request->ip());

            return Limit::perHour((int) config('essay_suite.why_school_per_user_per_hour', 10))->by('essay-why:'.$uid);
        });

        RateLimiter::for('essay-score-only', function (Request $request): Limit {
            $uid = (string) ($request->user()?->id ?? $request->ip());

            return Limit::perHour((int) config('essay_suite.score_only_per_user_per_hour', 10))->by('essay-score:'.$uid);
        });

        Event::listen(WebhookReceived::class, RecordOnboardingCheckoutFromStripeWebhook::class);

        $telemetry = LogLaravelAiSdkTelemetry::class;
        Event::listen(PromptingAgent::class, [$telemetry, 'handlePromptingAgent']);
        Event::listen(StreamingAgent::class, [$telemetry, 'handleStreamingAgent']);
        Event::listen(AgentStreamed::class, [$telemetry, 'handleAgentStreamed']);
        Event::listen(InvokingTool::class, [$telemetry, 'handleInvokingTool']);
        Event::listen(ToolInvoked::class, [$telemetry, 'handleToolInvoked']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
