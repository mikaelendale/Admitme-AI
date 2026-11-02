<?php

namespace Tests\Support;

use App\Models\AdmissionRequirement;
use App\Models\CollegeListSchool;
use App\Models\Institution;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceVersion;
use App\Models\User;

/**
 * Synthetic admissions sources and requirements, written directly (no fetching, no model calls).
 */
trait KnowledgeFixtures
{
    protected function institutionFor(CollegeListSchool $school, ?string $domain = 'synthetic-lakeside.edu'): Institution
    {
        return Institution::query()->firstOrCreate(['slug' => $school->school_slug], ['name' => $school->school_name, 'website_domain' => $domain]);
    }

    /**
     * @return array{0: KnowledgeSource, 1: KnowledgeSourceVersion}
     */
    protected function sourceFor(Institution $institution, string $url, string $type = KnowledgeSource::TYPE_OFFICIAL, ?User $owner = null, string $text = 'Synthetic admissions page text.'): array
    {
        $source = KnowledgeSource::query()->create([
            'institution_id' => $institution->id,
            'owner_user_id' => $owner?->id,
            'scope_key' => KnowledgeSource::scopeKey($institution, $owner),
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'title' => 'Synthetic page '.basename($url),
            'source_type' => $type,
            'status' => 'active',
            'checked_at' => now(),
        ]);

        $version = KnowledgeSourceVersion::query()->create([
            'knowledge_source_id' => $source->id,
            'content_text' => $text,
            'content_hash' => hash('sha256', $text.$url.microtime()),
            'http_status' => 200,
            'fetched_at' => now(),
            'extraction_status' => 'extracted',
        ]);

        $source->update(['current_version_id' => $version->id]);

        return [$source->fresh(), $version];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function requirementFrom(KnowledgeSourceVersion $version, array $attributes): AdmissionRequirement
    {
        $source = $version->source;

        return AdmissionRequirement::query()->create([
            'institution_id' => $source->institution_id,
            'owner_user_id' => $source->owner_user_id,
            'knowledge_source_id' => $source->id,
            'knowledge_source_version_id' => $version->id,
            'applicant_scope' => 'all',
            'review_status' => AdmissionRequirement::REVIEW_UNVERIFIED,
            'quote' => $attributes['quote'] ?? ($attributes['label'] ?? 'Quoted text'),
            ...$attributes,
        ]);
    }
}
