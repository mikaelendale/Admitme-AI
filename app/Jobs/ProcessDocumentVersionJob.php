<?php

namespace App\Jobs;

use App\Domain\Documents\CandidateExtractionService;
use App\Domain\Documents\DocumentTextExtractor;
use App\Domain\Documents\DocumentUnreadableException;
use App\Domain\Documents\DocumentVersionStatus;
use App\Models\DocumentPage;
use App\Models\DocumentVersion;
use App\Models\JobTracking;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads one document version: stores its page text, then records candidate values for the student to
 * review. Failures are visible on the document with a reason the student can act on.
 */
class ProcessDocumentVersionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $versionId) {}

    public function uniqueId(): string
    {
        return $this->versionId;
    }

    public function handle(DocumentTextExtractor $extractor, CandidateExtractionService $candidates): void
    {
        $version = DocumentVersion::query()->with('document')->find($this->versionId);

        if ($version === null || ! in_array($version->status, [DocumentVersionStatus::Queued, DocumentVersionStatus::Processing], true)) {
            return;
        }

        $tracking = JobTracking::query()->create([
            'user_id' => $version->user_id,
            'job_type' => JobTracking::TYPE_DOCUMENT_PROCESSING,
            'status' => JobTracking::STATUS_RUNNING,
            'payload' => ['document_id' => $version->document_id, 'document_version_id' => $version->id],
            'started_at' => now(),
        ]);

        $version->update(['status' => DocumentVersionStatus::Processing]);

        try {
            $pages = $extractor->pages((string) Storage::disk($version->disk)->get($version->path), $version->mime_type);
        } catch (DocumentUnreadableException $e) {
            $this->fail($version, $tracking, $e->getMessage());

            return;
        }

        DB::transaction(function () use ($version, $pages) {
            DocumentPage::query()->where('document_version_id', $version->id)->delete();

            foreach ($pages as $index => $text) {
                DocumentPage::query()->create([
                    'document_version_id' => $version->id,
                    'user_id' => $version->user_id,
                    'page_number' => $index + 1,
                    'text' => $text,
                ]);
            }

            $version->update(['page_count' => count($pages)]);
        });

        try {
            $found = $candidates->extract($version, $pages);
        } catch (Throwable $e) {
            report($e);
            $this->fail($version, $tracking, 'The text was read, but values could not be extracted automatically right now. Retry, or enter the values manually.');

            return;
        }

        // A replacement may have arrived while this ran; never resurrect a superseded version...
        $version->refresh();
        if ($version->status === DocumentVersionStatus::Processing) {
            $version->update([
                'status' => $found === [] ? DocumentVersionStatus::Ready : DocumentVersionStatus::NeedsReview,
                'processed_at' => now(),
            ]);
        }

        $tracking->forceFill(['status' => JobTracking::STATUS_COMPLETED, 'completed_at' => now()])->save();
    }

    private function fail(DocumentVersion $version, JobTracking $tracking, string $reason): void
    {
        $version->update(['status' => DocumentVersionStatus::Failed, 'failure_reason' => $reason, 'processed_at' => now()]);
        $tracking->forceFill(['status' => JobTracking::STATUS_FAILED, 'failed_at' => now(), 'error_message' => $reason])->save();
    }
}
