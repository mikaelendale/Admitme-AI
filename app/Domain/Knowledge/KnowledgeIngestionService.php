<?php

namespace App\Domain\Knowledge;

use App\Jobs\ExtractRequirementsJob;
use App\Models\Institution;
use App\Models\KnowledgePassage;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches an allowed page and stores it as a source version with searchable passages. Re-fetching
 * unchanged content only records the check; changed content becomes a new version and is re-extracted.
 *
 * Official and reference pages are filed as shared knowledge for the institution; any other page is the
 * adding student's private source, and its passages and requirements carry the same owner (D-016).
 */
final class KnowledgeIngestionService
{
    public function __construct(private readonly SourceUrlPolicy $policy) {}

    public function ingest(Institution $institution, string $url, ?User $addedBy, bool $studentSaysOfficial = false): KnowledgeSource
    {
        $checked = $this->policy->check($url, $institution, $studentSaysOfficial);
        $shared = $checked['type'] !== KnowledgeSource::TYPE_STUDENT_PROVIDED;
        $owner = $shared ? null : $addedBy;

        if (! $shared && $owner === null) {
            throw new \LogicException('A student-provided source needs the student who provided it.');
        }

        $source = KnowledgeSource::query()->firstOrNew([
            'scope_key' => KnowledgeSource::scopeKey($institution, $owner),
            'url_hash' => hash('sha256', $checked['url']),
        ]);

        if (! $source->exists) {
            $source->fill([
                'institution_id' => $institution->id,
                'owner_user_id' => $owner?->getKey(),
                'url' => $checked['url'],
                'host' => $checked['host'],
                'source_type' => $checked['type'],
                'status' => 'active',
                'added_by_user_id' => $addedBy?->getKey(),
            ]);
        }

        if (! $shared && $studentSaysOfficial) {
            $source->claimed_official = true;
        }

        // No redirects (a redirect could point at an internal address after the check), and the connection is
        // pinned to the address that passed the check so a second DNS answer cannot change it...
        $options = ['allow_redirects' => false];
        if ($checked['ip'] !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$checked['host']}:{$checked['port']}:{$checked['ip']}"]];
        }

        try {
            $response = Http::timeout((int) config('admitme.knowledge.fetch_timeout_seconds', 20))
                ->withHeaders(['User-Agent' => 'AdmitMeResearch/1.0 (+student admissions research)', 'Accept' => 'text/html,text/plain'])
                ->withOptions($options)
                ->get($checked['url']);
        } catch (Throwable $e) {
            return $this->failed($source, 'The page could not be reached: '.class_basename($e).'.');
        }

        if ($response->redirect()) {
            return $this->failed($source, 'That link redirects to another page'.($response->header('Location') ? ' ('.$response->header('Location').')' : '').'. Add the final page\'s link instead.');
        }

        if (! $response->successful()) {
            return $this->failed($source, "The page returned HTTP {$response->status()}.");
        }

        $body = $response->body();

        if (strlen($body) > (int) config('admitme.knowledge.max_fetch_bytes', 3_000_000)) {
            return $this->failed($source, 'The page is too large to store.');
        }

        $isHtml = str_contains(strtolower((string) $response->header('Content-Type')), 'html') || str_contains(substr($body, 0, 500), '<');
        $text = $isHtml ? HtmlText::text($body) : trim($body);

        if (mb_strlen($text) < 40) {
            return $this->failed($source, 'No readable text was found on the page.');
        }

        $hash = hash('sha256', $text);

        return DB::transaction(function () use ($source, $institution, $checked, $body, $isHtml, $text, $hash, $response) {
            $source->title = ($isHtml ? HtmlText::title($body) : null) ?? $source->title ?? $checked['host'];
            $source->status = 'active';
            $source->failure_reason = null;
            $source->checked_at = now();

            $current = $source->exists && $source->current_version_id !== null ? KnowledgeSourceVersion::query()->find($source->current_version_id) : null;

            if ($current !== null && $current->content_hash === $hash) {
                $source->save();

                return $source;
            }

            $source->save();

            $version = KnowledgeSourceVersion::query()->create([
                'knowledge_source_id' => $source->id,
                'content_text' => $text,
                'content_hash' => $hash,
                'http_status' => $response->status(),
                'fetched_at' => now(),
            ]);

            foreach (HtmlText::passages($text) as $position => $passage) {
                KnowledgePassage::query()->create([
                    'knowledge_source_version_id' => $version->id,
                    'institution_id' => $institution->id,
                    'owner_user_id' => $source->owner_user_id,
                    'position' => $position,
                    'text' => $passage,
                ]);
            }

            $source->update(['current_version_id' => $version->id]);

            ExtractRequirementsJob::dispatch($version->id)->afterCommit();

            return $source;
        });
    }

    private function failed(KnowledgeSource $source, string $reason): KnowledgeSource
    {
        $source->fill(['status' => 'failed', 'failure_reason' => $reason, 'checked_at' => now()])->save();

        return $source;
    }
}
