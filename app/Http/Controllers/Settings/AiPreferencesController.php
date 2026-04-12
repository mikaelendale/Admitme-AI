<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAiPreferencesRequest;
use App\Jobs\BuildUserAiContextJob;
use App\Models\OnboardingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiPreferencesController extends Controller
{
    public static function defaults(): array
    {
        return [
            'mentor_tone' => 'brutally_honest',
            'essay_feedback_style' => 'detailed_constructive',
        ];
    }

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $stored = is_array($user->ai_preferences) ? $user->ai_preferences : [];
        $profile = OnboardingProfile::query()->where('user_id', $user->id)->first();

        $lastContextAt = $profile?->last_ai_context_at?->toIso8601String();
        $ctx = is_array($profile?->ai_context) ? $profile->ai_context : [];
        $meta = isset($ctx['_meta']) && is_array($ctx['_meta']) ? $ctx['_meta'] : [];
        $lastUpdated = is_string($meta['last_updated'] ?? null) ? $meta['last_updated'] : $lastContextAt;

        return Inertia::render('settings/ai', [
            'preferences' => array_merge(self::defaults(), $stored),
            'aiContextLastUpdated' => $lastUpdated,
        ]);
    }

    public function update(UpdateAiPreferencesRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'ai_preferences' => $request->validated(),
        ])->save();

        return to_route('settings.ai.edit')->with('status', 'ai-preferences-updated');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        BuildUserAiContextJob::dispatch($request->user()->id, true);

        return to_route('settings.ai.edit')->with('status', 'ai-context-regeneration-queued');
    }
}
