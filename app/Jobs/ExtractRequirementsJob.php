<?php

namespace App\Jobs;

use App\Domain\Knowledge\RequirementExtractionService;
use App\Models\KnowledgeSourceVersion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads the requirements a newly fetched page version states. A failure leaves the page searchable and
 * marks extraction as failed, rather than inventing anything.
 */
class ExtractRequirementsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $versionId) {}

    public function uniqueId(): string
    {
        return $this->versionId;
    }

    public function handle(RequirementExtractionService $extraction): void
    {
        $version = KnowledgeSourceVersion::query()->with('source')->find($this->versionId);

        if ($version === null || $version->source->current_version_id !== $version->id) {
            return;
        }

        try {
            $extraction->extract($version);
        } catch (Throwable $e) {
            report($e);
            $version->update(['extraction_status' => 'failed']);
        }
    }
}
