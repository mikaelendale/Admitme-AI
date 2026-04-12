<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DangerDeleteResponsesRequest;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Models\OnboardingProfile;
use App\Models\OnboardingResponse;
use App\Models\OnboardingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivacyController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/privacy', [
            'legalVersion' => config('legal.documents_version'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'onboarded' => $user->onboarded,
                'notification_preferences' => $user->notification_preferences,
                'ai_preferences' => $user->ai_preferences,
            ],
            'onboarding_profile' => OnboardingProfile::query()
                ->where('user_id', $user->id)
                ->first()
                ?->setHidden([])
                ?->toArray(),
            'onboarding_responses' => OnboardingResponse::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get(['id', 'onboarding_session_id', 'question_key', 'step_key', 'source', 'answer_text', 'answer_json', 'created_at', 'updated_at'])
                ->toArray(),
            'onboarding_sessions' => OnboardingSession::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get(['id', 'session_uuid', 'status', 'last_step_key', 'started_at', 'completed_at', 'created_at'])
                ->toArray(),
            'subscription' => [
                'stripe_id' => $user->stripe_id,
                'subscribed' => $user->subscribed('default'),
            ],
        ];

        $filename = 'admitme-export-'.$user->id.'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function deleteResponses(DangerDeleteResponsesRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            OnboardingResponse::query()->where('user_id', $user->id)->delete();
            OnboardingSession::query()->where('user_id', $user->id)->delete();
            OnboardingProfile::query()->where('user_id', $user->id)->update([
                'ai_context' => null,
                'last_ai_context_at' => null,
            ]);
        });

        return to_route('settings.privacy.edit')->with('status', 'responses-deleted');
    }

    public function deleteEssays(): RedirectResponse
    {
        return to_route('settings.privacy.edit')->with('status', 'essays-not-implemented');
    }

    public function destroyAccount(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->subscribed('default')) {
            try {
                $user->subscription('default')?->cancelNow();
            } catch (\Throwable) {
                // continue best-effort delete
            }
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
