<?php

namespace App\Domain\Documents;

use App\Ai\Agents\DocumentFactExtractorAgent;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Domain\Students\StudentFieldRegistry;
use App\Models\DocumentVersion;
use App\Models\OnboardingProfile;
use App\Models\StudentFact;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Turns a model's extraction of one document into reviewable candidate facts.
 *
 * Model output is not trusted: a value is kept only when its quote really appears on the cited page,
 * values that do not fit the field are kept but flagged, differing values for the same field are all
 * flagged, and a value that disagrees with what the student already has becomes a `conflict`.
 * Nothing here changes the profile.
 */
final class CandidateExtractionService
{
    /**
     * Fields a document may provide candidates for.
     *
     * @var list<string>
     */
    public const EXTRACTABLE_FIELDS = [
        'curriculum',
        'current_grade',
        'graduation_year',
        'gpa_value',
        'gpa_scale',
        'gpa_type',
        'sat_score',
        'act_score',
    ];

    private const MAX_PROMPT_CHARS = 40000;

    /**
     * @param  list<string>  $pages
     * @return list<StudentFact>
     */
    public function extract(DocumentVersion $version, array $pages): array
    {
        $document = $version->document;

        $prompt = "Document kind: {$document->kind}. Title: {$document->title}.\n\n";
        foreach ($pages as $index => $text) {
            $prompt .= '[Page '.($index + 1)."]\n{$text}\n\n";
        }

        $response = DocumentFactExtractorAgent::make()->prompt(Str::limit($prompt, self::MAX_PROMPT_CHARS, "\n[document truncated]"));

        return $this->store($version, $pages, (array) ($response['candidates'] ?? []));
    }

    /**
     * @param  list<string>  $pages
     * @param  list<mixed>  $raw
     * @return list<StudentFact>
     */
    public function store(DocumentVersion $version, array $pages, array $raw): array
    {
        $accepted = [];

        foreach ($raw as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $field = (string) ($candidate['field'] ?? '');
            $page = (int) ($candidate['page'] ?? 0);
            $quote = trim((string) ($candidate['quote'] ?? ''));
            $value = trim((string) ($candidate['value'] ?? ''));

            if (! in_array($field, self::EXTRACTABLE_FIELDS, true) || $value === '' || $page < 1 || $page > count($pages)) {
                continue;
            }

            // The value must be sourced: its quote has to appear on the cited page...
            if ($quote === '' || ! $this->appearsIn($quote, $pages[$page - 1])) {
                continue;
            }

            $notes = array_filter([is_string($candidate['note'] ?? null) ? trim($candidate['note']) : null]);
            $ambiguous = (bool) ($candidate['ambiguous'] ?? false);

            $normalized = StudentFieldRegistry::normalize($field, $this->coerce($field, $value));
            if (Validator::make([$field => $normalized], StudentFieldRegistry::rules([$field]))->fails()) {
                // Keep it for the student to fix, but never as a usable value...
                $normalized = $value;
                $ambiguous = true;
                $notes[] = 'This doesn\'t fit the profile\'s format for this field — edit it before accepting.';
            }

            $accepted[] = compact('field', 'page', 'quote', 'normalized', 'ambiguous', 'notes');
        }

        // Different values for the same field in one document need the student's judgement...
        $byField = [];
        foreach ($accepted as $item) {
            $byField[$item['field']][] = json_encode($item['normalized']);
        }

        $profile = OnboardingProfile::query()->where('user_id', $version->user_id)->first();
        $facts = [];

        foreach ($accepted as $item) {
            if (count(array_unique($byField[$item['field']])) > 1) {
                $item['ambiguous'] = true;
                $item['notes'][] = 'The document shows more than one value for this field.';
            }

            // The review screen shows the current profile value beside a conflict, so no note is needed...
            $current = $profile?->getAttribute(StudentFieldRegistry::column($item['field']));
            $conflicts = $current !== null && $current !== '' && json_encode($this->comparable($current)) !== json_encode($this->comparable($item['normalized']));

            $facts[] = StudentFact::query()->create([
                'user_id' => $version->user_id,
                'field_key' => $item['field'],
                'value' => $item['normalized'],
                'status' => $conflicts ? FactStatus::Conflict : FactStatus::Candidate,
                'source_type' => ChangeSource::SOURCE_DOCUMENT,
                'source_ref' => [
                    'document_id' => $version->document_id,
                    'document_version_id' => $version->id,
                    'version' => $version->version,
                    'page' => $item['page'],
                    'quote' => Str::limit($item['quote'], 300, '…'),
                    'ambiguous' => $item['ambiguous'],
                ],
                'actor_type' => ChangeSource::ACTOR_SYSTEM,
                'profile_revision' => (int) ($profile?->revision ?? 0),
                'review_state' => StudentFact::REVIEW_PENDING,
                'note' => $item['notes'] !== [] ? implode(' ', array_unique($item['notes'])) : null,
            ]);
        }

        return $facts;
    }

    private function appearsIn(string $quote, string $pageText): bool
    {
        $normalize = fn (string $text) => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text)));

        return str_contains($normalize($pageText), $normalize($quote));
    }

    private function coerce(string $field, string $value): mixed
    {
        return match (StudentFieldRegistry::get($field)['type']) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'decimal' => is_numeric($value) ? $value : $value,
            default => $value,
        };
    }

    private function comparable(mixed $value): mixed
    {
        return is_numeric($value) ? (string) (0 + $value) : $value;
    }
}
