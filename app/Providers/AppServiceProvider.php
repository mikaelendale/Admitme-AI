<?php

namespace App\Providers;

use App\Ai\Agents\Subagents\SubagentActivity;
use App\Ai\Streaming\EarlyToolResults;
use App\Listeners\LogLaravelAiSdkTelemetry;
use App\Listeners\NotifyUserOfInvoicePaymentFailure;
use App\Listeners\RecordOnboardingCheckoutFromStripeWebhook;
use App\Models\User;
use App\Services\Billing\ProfileResumePdfImportLimiter;
use App\Services\CollegeListCouncil\CollegeListCouncilOrchestratorContract;
use App\Services\CollegeListCouncil\FakeCollegeListCouncilOrchestrator;
use App\Services\CollegeListCouncil\RealCollegeListCouncilOrchestrator;
use App\Support\ConversationOwnership;
use App\Support\FeatureQuotaManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Events\AgentFailedOver;
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
        $this->app->bind(PdfParser::class, fn () => new PdfParser);

        // What running specialists are doing, per mentor run (reset between requests and queued jobs).
        $this->app->scoped(SubagentActivity::class);
        $this->app->scoped(EarlyToolResults::class);

        $this->app->singleton(ProfileResumePdfImportLimiter::class, fn (): ProfileResumePdfImportLimiter => ProfileResumePdfImportLimiter::fromConfig());

        $this->app->singleton(FeatureQuotaManager::class, fn (): FeatureQuotaManager => new FeatureQuotaManager);

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

        // A provider that could not answer (e.g. out of credits) and the agent moved on to the next one.
        Event::listen(AgentFailedOver::class, fn (AgentFailedOver $event) => Log::warning('AI provider failed over', [
            'agent' => class_basename($event->agent),
            'from' => $event->provider->name(),
            'model' => $event->model,
            'reason' => $event->exception->getMessage(),
        ]));

        // The mentor's chat shows each tool's result as soon as the tool finishes (see EarlyToolResults).
        Event::listen(ToolInvoked::class, fn (ToolInvoked $event) => app(EarlyToolResults::class)->record($event));

        // Named limiters keep their own counters (plain "throttle:N,1" shares one counter per user across routes).
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(5)->by('exports:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('export-downloads', fn (Request $request) => Limit::perMinute(30)->by('export-downloads:'.($request->user()?->getKey() ?? $request->ip())));
        // The extension polls for commands while assistance is active; keyed by its token, not shared with the web app.
        RateLimiter::for('browser-bridge', fn (Request $request) => Limit::perMinute(240)->by('browser:'.($request->bearerToken() !== null ? sha1($request->bearerToken()) : $request->ip())));

        // The SDK's continue() does not check ownership, so every conversation id is authorized here...
        Gate::define('access-conversation', fn (User $user, string $conversationId) => ConversationOwnership::userOwnsConversation((int) $user->getKey(), $conversationId)
            ? Response::allow()
            : Response::deny('Invalid conversation.'));

        Event::listen(WebhookReceived::class, RecordOnboardingCheckoutFromStripeWebhook::class);
        Event::listen(WebhookReceived::class, NotifyUserOfInvoicePaymentFailure::class);

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
