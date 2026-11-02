<?php

namespace App\Domain\Knowledge;

use App\Ai\Agents\RequirementExtractorAgent;
use App\Models\AdmissionRequirement;
use App\Models\KnowledgeSourceVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores the requirements a source version states. Model output is checked: the quote must appear in
 * the page, and dates/times/currencies must parse (otherwise they stay null — never guessed). Requirements
 * inherit their source's visibility; disagreements between sources are found when read
 * (RequirementConflicts), because which sources count depends on who is looking.
 */
final class RequirementExtractionService
{
    private const MAX_PROMPT_CHARS = 30000;

    /**
     * @return list<AdmissionRequirement>
     */
    public function extract(KnowledgeSourceVersion $version): array
    {
        $source = $version->source;
        $response = $this->ask($source->title, $source->url, $version->content_text);

        return $this->store($version, (array) ($response['requirements'] ?? []));
    }

    /**
     * The model's extraction for one page, exactly as production asks for it (also used by the evaluation set).
     */
    public function ask(?string $title, string $url, string $text): mixed
    {
        return RequirementExtractorAgent::make()->prompt(
            "Page title: {$title}\nURL: {$url}\n\n".Str::limit($text, self::MAX_PROMPT_CHARS, "\n[page truncated]"),
        );
    }

    /**
     * Keep only well-formed requirements whose quote appears in the page; everything else is dropped.
     *
     * @param  list<mixed>  $raw
     * @return array{kept: list<array<string, mixed>>, dropped: int}
     */
    public function validate(string $pageText, array $raw): array
    {
        $kept = [];
        $dropped = 0;

        foreach ($raw as $item) {
            $quote = is_array($item) ? trim((string) ($item['quote'] ?? '')) : '';
            $label = is_array($item) ? trim((string) ($item['label'] ?? '')) : '';

            if (! is_array($item) || ! in_array($item['requirement_type'] ?? null, AdmissionRequirement::TYPES, true) || $quote === '' || $label === '' || ! $this->appearsIn($quote, $pageText)) {
                $dropped++;

                continue;
            }

            $kept[] = [
                'program' => $this->text($item['program'] ?? null, 200),
                'cycle_label' => $this->text($item['cycle_label'] ?? null, 64),
                'requirement_type' => $item['requirement_type'],
                'label' => Str::limit($label, 200, ''),
                'value_text' => $this->text($item['value_text'] ?? null, 2000),
                'deadline_date' => $this->date($item['deadline_date'] ?? null),
                'deadline_time' => $this->time($item['deadline_time'] ?? null),
                'timezone' => $this->timezone($item['timezone'] ?? null),
                'amount' => is_numeric($item['amount'] ?? null) ? (float) $item['amount'] : null,
                'currency' => $this->currency($item['currency'] ?? null),
                'conditions' => $this->text($item['conditions'] ?? null, 2000),
                'applicant_scope' => in_array($item['applicant_scope'] ?? null, ['international', 'domestic'], true) ? $item['applicant_scope'] : 'all',
                'quote' => Str::limit($quote, 500, '…'),
            ];
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<AdmissionRequirement>
     */
    public function store(KnowledgeSourceVersion $version, array $raw): array
    {
        $source = $version->source;

        return DB::transaction(function () use ($version, $source, $raw) {
            // The newest version of a page replaces what its older versions said...
            AdmissionRequirement::query()
                ->where('knowledge_source_id', $source->id)
                ->where('knowledge_source_version_id', '!=', $version->id)
                ->whereNull('retired_at')
                ->update(['retired_at' => now()]);

            $stored = [];

            foreach ($this->validate((string) $version->content_text, $raw)['kept'] as $attributes) {
                $stored[] = AdmissionRequirement::query()->create([
                    ...$attributes,
                    'institution_id' => $source->institution_id,
                    'owner_user_id' => $source->owner_user_id,
                    'knowledge_source_id' => $source->id,
                    'knowledge_source_version_id' => $version->id,
                    'review_status' => AdmissionRequirement::REVIEW_UNVERIFIED,
                ]);
            }

            $version->update(['extraction_status' => 'extracted']);

            return $stored;
        });
    }

    private function appearsIn(string $quote, string $text): bool
    {
        $normalize = fn (string $value) => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));

        return str_contains($normalize($text), $normalize($quote));
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $max, '…') : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }

    private function timezone(mixed $value): ?string
    {
        return is_string($value) && in_array($value, timezone_identifiers_list(), true) ? $value : null;
    }

    private function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z]{3}$/', $value) === 1 ? strtoupper($value) : null;
    }
}
