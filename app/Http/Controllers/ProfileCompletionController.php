<?php

namespace App\Http\Controllers;

use App\Ai\Agents\NoCapAgent;
use App\Ai\Agents\ProfilePasteExtractionAgent;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\StudentFieldRegistry;
use App\Domain\Students\StudentProfileService;
use App\Models\OnboardingProfile;
use App\Services\Ai\AiContextService;
use App\Services\Billing\ProfileResumePdfImportLimiter;
use App\Services\Profile\ApplyProfileExtractionToModel;
use App\Services\Profile\GpaNormalizer;
use App\Services\Profile\ProfileCompletenessCalculator;
use App\Services\Profile\ProfileModesResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

class ProfileCompletionController extends Controller
{
    public function storeDump(
        Request $request,
        ApplyProfileExtractionToModel $applyExtraction,
        GpaNormalizer $gpaNormalizer,
        ProfileCompletenessCalculator $completeness,
        ProfileModesResolver $modesResolver,
        ProfileResumePdfImportLimiter $pdfImportLimiter,
    ): RedirectResponse|JsonResponse {
        return $this->processProfileDump(
            $request,
            $applyExtraction,
            $gpaNormalizer,
            $completeness,
            $modesResolver,
            $pdfImportLimiter,
            fromPdfResume: false,
        );
    }

    public function storeResume(
        Request $request,
        ApplyProfileExtractionToModel $applyExtraction,
        GpaNormalizer $gpaNormalizer,
        ProfileCompletenessCalculator $completeness,
        ProfileModesResolver $modesResolver,
        ProfileResumePdfImportLimiter $pdfImportLimiter,
        PdfParser $pdfParser,
    ): RedirectResponse {
        $request->validate([
            'resume' => ['required', File::types(['pdf'])->max(10240)],
        ]);

        $uploaded = $request->file('resume');
        if ($uploaded === null) {
            return back()->withErrors(['resume' => 'Could not read the uploaded file.']);
        }

        $path = $uploaded->getRealPath() ?: $uploaded->getPathname();
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return back()->withErrors(['resume' => 'Could not read the uploaded file.']);
        }

        try {
            $pdf = $pdfParser->parseContent($contents);
            $text = trim($pdf->getText());
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['resume' => 'Could not read this PDF.']);
        }

        if ($text === '') {
            $profile = OnboardingProfile::query()->firstOrCreate(
                ['user_id' => $request->user()->id],
                ['status' => 'completed']
            );
            $profile->resume_pdf_unreadable = true;
            $profile->save();

            return back()->withErrors(['resume' => 'No text found in this PDF. Add details manually in the next step.']);
        }

        $request->merge(['profile_dump_text' => $text]);

        return $this->processProfileDump(
            $request,
            $applyExtraction,
            $gpaNormalizer,
            $completeness,
            $modesResolver,
            $pdfImportLimiter,
            fromPdfResume: true,
        );
    }

    public function updateClarify(Request $request, StudentProfileService $profiles): RedirectResponse
    {
        $keys = [
            'current_grade',
            'gpa_value',
            'gpa_scale',
            'gpa_type',
            'curriculum',
            'sat_score',
            'act_score',
            'test_optional_status',
            'citizenship_residency',
            'location_country',
            'location_region',
        ];

        $request->validate(StudentFieldRegistry::rules($keys) + self::revisionRule());

        $this->saveProfileStep($request, $profiles, $request->only($keys), 2, 'profile-completion.clarify');

        return redirect()->route('profile-overview')->with('status', 'profile-clarify-saved');
    }

    public function updateGoals(Request $request, StudentProfileService $profiles): RedirectResponse
    {
        $keys = ['dream_schools', 'intended_majors', 'region_preferences', 'campus_size_pref', 'financial_aid_need_percent'];

        $validated = $request->validate(StudentFieldRegistry::rules($keys) + self::revisionRule());

        // This form always submits the whole step, so a missing field means "cleared"...
        $changes = [];
        foreach ($keys as $key) {
            $changes[$key] = $validated[$key] ?? null;
        }

        $this->saveProfileStep($request, $profiles, $changes, 3, 'profile-completion.goals');

        return redirect()->route('profile-overview')->with('status', 'profile-goals-saved');
    }

    public function updateReview(Request $request, StudentProfileService $profiles): RedirectResponse
    {
        $keys = ['student_summary', 'spike_summary'];

        $request->validate(StudentFieldRegistry::rules($keys) + self::revisionRule());

        $this->saveProfileStep($request, $profiles, $request->only($keys), 4, 'profile-completion.review');

        return redirect()->route('profile-overview')->with('status', 'profile-review-saved');
    }

    /**
     * Save one manual profile step through the shared profile service, marking the step complete.
     *
     * @param  array<string, mixed>  $changes
     */
    private function saveProfileStep(Request $request, StudentProfileService $profiles, array $changes, int $step, string $via): void
    {
        $user = $request->user();
        $profile = $this->requireProfile($request);
        $expectedRevision = $request->filled('expected_revision') ? $request->integer('expected_revision') : null;

        $profiles->update(
            $user,
            $changes,
            $expectedRevision,
            ChangeSource::studentManual($user, $via),
            ['profile_completion_state' => $this->mergeCompletionState($profile, $step)],
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private static function revisionRule(): array
    {
        return ['expected_revision' => ['sometimes', 'nullable', 'integer', 'min:1']];
    }

    public function updateMentor(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mentor_tone' => ['required', Rule::in(['brutally_honest', 'balanced', 'supportive'])],
            'weekly_focus_nudges' => ['nullable', 'boolean'],
            'parent_access' => ['nullable', 'boolean'],
            'mood_checkins' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $prefs = is_array($user->ai_preferences) ? $user->ai_preferences : [];
        $prefs['mentor_tone'] = $validated['mentor_tone'];
        $prefs['weekly_focus_nudges'] = (bool) ($validated['weekly_focus_nudges'] ?? true);
        $prefs['parent_access'] = (bool) ($validated['parent_access'] ?? false);
        $prefs['mood_checkins'] = (bool) ($validated['mood_checkins'] ?? false);
        $user->ai_preferences = $prefs;
        $user->save();

        $profile = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if ($profile) {
            $profile->profile_completion_state = $this->mergeCompletionState($profile, 5);
            $profile->save();
        }

        return redirect()->route('profile-overview')->with('status', 'profile-mentor-saved');
    }

    public function refreshInsights(Request $request, AiContextService $aiContextService): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $profile = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if ($profile === null || trim((string) $profile->profile_dump_text) === '') {
            return back()->withErrors(['insights' => 'Add a profile dump first.']);
        }

        $paste = trim((string) $profile->profile_dump_text);
        $prompt = "Student profile paste:\n\n---\n{$paste}\n---\n\nProduce the structured admissions read now.";

        try {
            $aiResponse = NoCapAgent::make()->prompt($prompt);
            if (! $aiResponse instanceof StructuredAgentResponse) {
                return back()->withErrors(['insights' => 'Could not refresh insights.']);
            }

            $structured = $aiResponse->structured;
            if (! is_array($structured)) {
                return back()->withErrors(['insights' => 'Could not refresh insights.']);
            }

            $mapped = $this->mapNoCapToAiContext($structured, $paste, $profile);

            $existing = is_array($profile->ai_context) ? $profile->ai_context : [];
            $merged = $aiContextService->mergeAndCap($existing, $mapped);
            $merged = $aiContextService->setLastUpdated($merged);

            $profile->forceFill([
                'ai_context' => $merged,
                'last_ai_context_at' => now(),
            ])->save();

            if ($request->wantsJson()) {
                return response()->json(['ok' => true]);
            }

            return redirect()->route('profile-overview')->with('status', 'profile-insights-refreshed');
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['insights' => 'Insights refresh failed.']);
        }
    }

    private function processProfileDump(
        Request $request,
        ApplyProfileExtractionToModel $applyExtraction,
        GpaNormalizer $gpaNormalizer,
        ProfileCompletenessCalculator $completeness,
        ProfileModesResolver $modesResolver,
        ProfileResumePdfImportLimiter $pdfImportLimiter,
        bool $fromPdfResume,
    ): RedirectResponse|JsonResponse {
        $user = $request->user();
        $validated = $request->validate([
            'profile_dump_text' => ['nullable', 'string', 'max:65535'],
        ]);

        $text = trim((string) ($validated['profile_dump_text'] ?? ''));

        if ($text === '') {
            return back()->withErrors(['profile_dump_text' => 'Even just your GPA and one activity is enough to start.']);
        }

        $profile = OnboardingProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'completed']
        );

        $hash = hash('sha256', $text);
        $cacheHit = $profile->extraction_source_hash === $hash && is_array($profile->extraction_draft_json);

        if ($fromPdfResume && ! $cacheHit && ! $pdfImportLimiter->canRunNewPdfExtraction($user)) {
            return back()->withErrors(['resume' => $pdfImportLimiter->blockMessage()]);
        }

        try {
            DB::transaction(function () use ($profile, $text, $hash, $applyExtraction, $gpaNormalizer, $completeness, $modesResolver, $pdfImportLimiter, $user, $fromPdfResume, $cacheHit): void {
                $profile->profile_dump_text = $text;
                $profile->resume_pdf_unreadable = false;

                if ($cacheHit) {
                    $profile->profile_completion_state = $this->mergeCompletionState($profile, 1);
                    $profile->save();

                    return;
                }

                $prompt = "Student profile paste:\n\n---\n{$text}\n---\n\nExtract structured fields now.";

                $response = ProfilePasteExtractionAgent::make()->prompt($prompt);
                if (! $response instanceof StructuredAgentResponse) {
                    throw new \RuntimeException('Extraction did not return structured output.');
                }
                $structured = $response->structured;
                if (! is_array($structured)) {
                    throw new \RuntimeException('Invalid extraction payload.');
                }

                $applyExtraction->apply($profile, $structured);
                $profile->extraction_source_hash = $hash;
                $profile->last_profile_extraction_at = now();

                $this->applyGpaNormalization($profile, $gpaNormalizer);

                $calc = $completeness->calculate($profile);
                $profile->profile_completeness_score = $calc['score'];
                $profile->completeness_callouts_json = $calc['callouts'];

                $profile->profile_modes_json = $modesResolver->resolve($profile);

                $profile->profile_completion_state = $this->mergeCompletionState($profile, 1);
                $profile->save();

                if ($fromPdfResume && ! $pdfImportLimiter->hasUnlimitedImports($user)) {
                    $user->increment('profile_pdf_resume_parse_count');
                }
            });
        } catch (Throwable $e) {
            report($e);

            $msg = 'We could not parse your profile right now. Try again shortly.';

            return back()->withErrors(
                $fromPdfResume ? ['resume' => $msg] : ['profile_dump_text' => $msg],
            );
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('profile-overview')->with('status', 'profile-dump-saved');
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function mapNoCapToAiContext(array $structured, string $paste, OnboardingProfile $profile): array
    {
        $excerpt = mb_substr(trim($paste), 0, 500);

        $dimensions = [];
        if (isset($structured['dimensions']) && is_array($structured['dimensions'])) {
            foreach ($structured['dimensions'] as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $key = $d['key'] ?? null;
                if (! is_string($key)) {
                    continue;
                }
                $dimensions[$key] = [
                    'score' => $d['score'] ?? null,
                    'verdict' => $d['verdict'] ?? null,
                    'label' => $d['label'] ?? null,
                ];
            }
        }

        $chips = [];
        if (isset($structured['insight_tokens']) && is_array($structured['insight_tokens'])) {
            foreach ($structured['insight_tokens'] as $t) {
                if (is_array($t) && isset($t['text']) && is_string($t['text'])) {
                    $chips[] = $t['text'];
                }
            }
        }

        $takeaways = [];
        if (isset($structured['takeaways']) && is_array($structured['takeaways'])) {
            foreach ($structured['takeaways'] as $line) {
                if (is_string($line)) {
                    $takeaways[] = $line;
                }
            }
        }

        return [
            'headline' => isset($structured['headline']) && is_string($structured['headline'])
                ? $structured['headline']
                : null,
            'profile' => [
                'excerpt' => $excerpt !== '' ? $excerpt : null,
                'grade' => $profile->current_grade,
                'graduation_year' => $profile->graduation_year,
            ],
            'radar' => $dimensions !== [] ? $dimensions : null,
            'signals' => $chips !== [] ? array_values(array_slice($chips, 0, 12)) : null,
            'takeaways' => $takeaways !== [] ? array_values(array_slice($takeaways, 0, 8)) : null,
            'structured_fields' => [
                'gpa_value' => $profile->gpa_value,
                'sat_score' => $profile->sat_score,
                'act_score' => $profile->act_score,
            ],
        ];
    }

    private function applyGpaNormalization(OnboardingProfile $profile, GpaNormalizer $gpaNormalizer): void
    {
        $v = $profile->gpa_value !== null ? (float) $profile->gpa_value : null;
        $s = $profile->gpa_scale !== null ? (float) $profile->gpa_scale : null;
        $norm = $gpaNormalizer->toUsFourPointScale($v, $s, $profile->curriculum);
        if ($norm['gpa_normalized_4'] !== null) {
            $profile->gpa_normalized_4 = $norm['gpa_normalized_4'];
        }
    }

    private function requireProfile(Request $request): OnboardingProfile
    {
        $profile = OnboardingProfile::query()->where('user_id', $request->user()->id)->first();
        if ($profile === null) {
            abort(404, 'Profile not found.');
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeCompletionState(OnboardingProfile $profile, int $highestCompletedStep): array
    {
        $state = is_array($profile->profile_completion_state) ? $profile->profile_completion_state : [];
        $done = isset($state['completed_steps']) && is_array($state['completed_steps']) ? $state['completed_steps'] : [];
        if (! in_array($highestCompletedStep, $done, true)) {
            $done[] = $highestCompletedStep;
        }
        $state['completed_steps'] = array_values(array_unique($done));
        $state['current_step'] = min(5, $highestCompletedStep + 1);

        return $state;
    }
}
