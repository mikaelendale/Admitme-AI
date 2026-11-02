<?php

namespace App\Domain\Students;

use App\Domain\Shared\ChangeSource;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The guided profile questions, shared by first-run onboarding and the later "Profile setup" page. Every
 * save goes through StudentProfileService, so the same validation, revisions, provenance and audit apply
 * as for any other edit, and "don't know / not applicable" is a valid answer.
 */
final class GuidedProfileSetup
{
    public function __construct(private readonly StudentProfileService $profiles) {}

    /**
     * Every field the guided steps ask about.
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (array $section) => $section['keys'], array_values(GuidedOnboarding::SECTIONS)))));
    }

    /**
     * The sections with their fields and the student's current answers.
     *
     * @return list<array<string, mixed>>
     */
    public static function sections(?OnboardingProfile $profile): array
    {
        $state = GuidedOnboarding::state($profile);
        $sections = [];

        foreach (GuidedOnboarding::SECTIONS as $key => $section) {
            $sections[] = [
                'key' => $key,
                'title' => $section['title'],
                'intro' => $section['intro'],
                'completed' => in_array($key, $state['completed'], true),
                'fields' => self::fields($profile, $section['keys']),
            ];
        }

        return $sections;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    public static function fields(?OnboardingProfile $profile, array $keys): array
    {
        $unknown = (array) ($profile?->unknown_fields_json ?? []);

        return array_map(fn (string $field) => [
            ...GuidedOnboarding::field($field),
            'value' => self::formValue($profile?->getAttribute(StudentFieldRegistry::column($field))),
            'unknown' => in_array($field, $unknown, true),
        ], $keys);
    }

    /**
     * Questions still blocking an upcoming task, grouped where any one answer is enough
     * (e.g. a GPA or a test score).
     *
     * @return list<array{task: string, label: string, keys: list<string>}>
     */
    public static function missingQuestions(?OnboardingProfile $profile): array
    {
        $unknown = (array) ($profile?->unknown_fields_json ?? []);
        $answered = function (string $key) use ($profile, $unknown): bool {
            $value = $profile?->getAttribute(StudentFieldRegistry::column($key));

            return in_array($key, $unknown, true) || ($value !== null && $value !== '' && $value !== []);
        };
        $asked = array_flip(self::allKeys());
        $out = [];
        $seen = [];

        foreach (GuidedOnboarding::READINESS as $task => $definition) {
            foreach ($definition['keys'] as $requirement) {
                // Only fields the guided steps can answer (budget_band, for instance, is set elsewhere).
                $alternatives = array_values(array_filter((array) $requirement, fn (string $key) => isset($asked[$key])));
                if ($alternatives === [] || collect((array) $requirement)->contains($answered)) {
                    continue;
                }
                $id = implode('|', $alternatives);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = ['task' => $definition['label'], 'label' => count($alternatives) > 1 ? 'Any one of these' : StudentFieldRegistry::get($alternatives[0])['label'], 'keys' => $alternatives];
            }
        }

        return $out;
    }

    /**
     * Saved answers in plain words, for the review step.
     *
     * @return list<array{section: string, label: string, value: string|null, unknown: bool}>
     */
    public static function summary(?OnboardingProfile $profile): array
    {
        $out = [];
        foreach (GuidedOnboarding::SECTIONS as $key => $section) {
            foreach (self::fields($profile, $section['keys']) as $field) {
                $value = $field['value'] === '' ? null : (string) $field['value'];
                if ($value !== null && is_array($field['options']) && isset($field['options'][$value])) {
                    $value = $field['options'][$value];
                }
                $out[] = ['section' => $key, 'label' => $field['label'], 'value' => $value, 'unknown' => $field['unknown']];
            }
        }

        return $out;
    }

    public static function formValue(mixed $value): mixed
    {
        return match (true) {
            $value === null => '',
            is_array($value) => implode(', ', array_map('strval', $value)),
            default => (string) $value,
        };
    }

    /**
     * List fields are typed as comma-separated text in the forms.
     *
     * @param  list<string>  $keys
     */
    public static function normaliseLists(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            if (StudentFieldRegistry::get($key)['type'] === 'list' && is_string($request->input($key))) {
                $request->merge([$key => array_values(array_filter(array_map('trim', explode(',', $request->input($key))), fn ($item) => $item !== ''))]);
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function rules(array $keys): array
    {
        return [
            ...StudentFieldRegistry::rules($keys),
            'unknown' => ['sometimes', 'array'],
            'unknown.*' => ['string', 'in:'.implode(',', $keys ?: ['_'])],
            'expected_revision' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Save the given fields (a field marked "don't know" is cleared and recorded as unknown). When
     * `$completeSection` is set, that guided section is marked done and the next one becomes current.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $validated
     */
    public function save(User $user, array $keys, Request $request, array $validated, string $origin, ?string $completeSection = null): void
    {
        $unknown = array_values((array) ($validated['unknown'] ?? []));
        $expected = isset($validated['expected_revision']) && (int) $validated['expected_revision'] > 0 ? (int) $validated['expected_revision'] : null;

        DB::transaction(function () use ($user, $keys, $unknown, $expected, $request, $origin, $completeSection) {
            $profile = $this->profiles->profileFor($user);
            $source = ChangeSource::studentManual($user, $origin);

            $changes = [];
            foreach ($keys as $key) {
                $changes[$key] = in_array($key, $unknown, true) ? null : $request->input($key);
            }

            $extra = $completeSection !== null ? ['profile_completion_state' => GuidedOnboarding::stateAfter($profile, $completeSection)] : [];
            $result = $this->profiles->update($user, $changes, $expected, $source, $extra);

            if ($keys !== []) {
                $this->profiles->setUnknownFields($user, $keys, $unknown, $result->revisionAfter, $source);
            }
        });
    }
}
