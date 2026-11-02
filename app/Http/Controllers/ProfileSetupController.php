<?php

namespace App\Http\Controllers;

use App\Domain\Students\GuidedOnboarding;
use App\Domain\Students\GuidedProfileSetup;
use App\Models\Document;
use App\Models\OnboardingProfile;
use App\Models\StudentFact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guided, resumable profile setup after onboarding. Each section saves through the shared profile service
 * (GuidedProfileSetup), so the same validation, revisions, provenance and audit apply as everywhere else.
 */
class ProfileSetupController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $profile = OnboardingProfile::query()->where('user_id', $user->getKey())->first();
        $state = GuidedOnboarding::state($profile);
        $requested = $request->query('section');
        $missing = GuidedProfileSetup::missingQuestions($profile);

        return Inertia::render('profile-setup', [
            'sections' => GuidedProfileSetup::sections($profile),
            'current' => is_string($requested) && isset(GuidedOnboarding::SECTIONS[$requested]) ? $requested : $state['current'],
            'revision' => (int) ($profile?->revision ?? 0),
            'readiness' => GuidedOnboarding::readiness($profile),
            'missing' => $missing,
            'missingFields' => GuidedProfileSetup::fields($profile, array_values(array_unique(array_merge([], ...array_column($missing, 'keys'))))),
            'summary' => GuidedProfileSetup::summary($profile),
            'documents' => [
                'count' => Document::query()->where('user_id', $user->getKey())->count(),
                'pendingValues' => StudentFact::query()->where('user_id', $user->getKey())->where('review_state', StudentFact::REVIEW_PENDING)->count(),
            ],
        ]);
    }

    public function save(Request $request, string $section, GuidedProfileSetup $setup): RedirectResponse
    {
        abort_unless(isset(GuidedOnboarding::SECTIONS[$section]), 404);

        $keys = GuidedOnboarding::SECTIONS[$section]['keys'];
        GuidedProfileSetup::normaliseLists($request, $keys);
        $validated = $request->validate(GuidedProfileSetup::rules($keys));

        $setup->save($request->user(), $keys, $request, $validated, "profile-setup.{$section}", $section);

        $next = GuidedOnboarding::state(OnboardingProfile::query()->where('user_id', $request->user()->getKey())->first())['current'];

        return redirect()->route('profile-setup', ['section' => $next])->with('status', 'profile-setup-saved');
    }

    /**
     * Answers to the targeted questions on the review step (any guided field, without finishing a section).
     */
    public function answers(Request $request, GuidedProfileSetup $setup): RedirectResponse
    {
        $keys = array_values(array_intersect(GuidedProfileSetup::allKeys(), (array) $request->input('keys', [])));
        abort_if($keys === [], 422, 'No answers to save.');

        GuidedProfileSetup::normaliseLists($request, $keys);
        $validated = $request->validate(GuidedProfileSetup::rules($keys));

        $setup->save($request->user(), $keys, $request, $validated, 'profile-setup.review');

        return redirect()->route('profile-setup', ['section' => 'review'])->with('status', 'profile-setup-saved');
    }
}
