<?php

namespace App\Domain\Knowledge;

use App\Domain\Documents\DocumentVersionStatus;
use App\Models\DocumentPage;
use App\Models\Institution;
use App\Models\KnowledgePassage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Keyword passage search with exact filters applied in the query, before scoring:
 * institution knowledge is filtered to one institution's current source versions that the viewer may see
 * (shared sources and their own private ones); private document text is filtered to the requesting
 * student's own, non-superseded documents. Results never mix students.
 */
final class KnowledgeSearch
{
    private const STOP_WORDS = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'for', 'in', 'on', 'is', 'are', 'what', 'when', 'how', 'do', 'does', 'i', 'my', 'me', 'it', 'be', 'with', 'at', 'by', 'from', 'this', 'that', 'can', 'need', 'about'];

    /**
     * @return Collection<int, array{passage: KnowledgePassage, score: float}>
     */
    public function institutionPassages(Institution $institution, string $query, User $viewer, int $limit = 8): Collection
    {
        $passages = KnowledgePassage::query()
            ->where('institution_id', $institution->id)
            ->visibleTo($viewer)
            ->whereHas('version.source', fn ($q) => $q->where('status', 'active')->whereColumn('knowledge_sources.current_version_id', 'knowledge_passages.knowledge_source_version_id'))
            ->with('version.source')
            ->get();

        return $this->rank($passages, fn (KnowledgePassage $passage) => $passage->text, $query, $limit)
            ->map(fn (array $hit) => ['passage' => $hit['item'], 'score' => $hit['score']]);
    }

    /**
     * Search only this student's own document pages (current versions).
     *
     * @return Collection<int, array{page: DocumentPage, score: float}>
     */
    public function privateDocumentPages(User $student, string $query, int $limit = 5): Collection
    {
        $pages = DocumentPage::query()
            ->where('user_id', $student->getKey())
            ->whereHas('version', fn ($q) => $q->where('user_id', $student->getKey())->where('status', '!=', DocumentVersionStatus::Superseded->value))
            ->with('version.document')
            ->get();

        return $this->rank($pages, fn (DocumentPage $page) => $page->text, $query, $limit)
            ->map(fn (array $hit) => ['page' => $hit['item'], 'score' => $hit['score']]);
    }

    /**
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @param  callable(T): string  $text
     * @return Collection<int, array{item: T, score: float}>
     */
    private function rank(Collection $items, callable $text, string $query, int $limit): Collection
    {
        $terms = $this->terms($query);

        if ($terms === []) {
            return $items->take($limit)->map(fn ($item) => ['item' => $item, 'score' => 0.0])->values();
        }

        return $items
            ->map(function ($item) use ($terms, $text) {
                $haystack = Str::lower($text($item));
                $score = 0.0;

                foreach ($terms as $term) {
                    $count = substr_count($haystack, $term);
                    $score += $count > 0 ? 1 + log(1 + $count) : 0;
                }

                return ['item' => $item, 'score' => $score];
            })
            ->filter(fn (array $hit) => $hit['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($query)) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) > 1 && ! in_array($word, self::STOP_WORDS, true))));
    }
}
