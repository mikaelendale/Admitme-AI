<?php

namespace App\Domain\Documents;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\ChangeSource;
use App\Jobs\ProcessDocumentVersionJob;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores student documents as immutable, hashed versions in private storage and queues them for reading.
 * Replacing a document adds a version: the old file and its history stay; unreviewed values from the old
 * version are retired so they cannot be accepted against a file the student replaced.
 */
final class DocumentService
{
    public const DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException when the file is identical to the document's current version.
     */
    public function upload(User $student, UploadedFile $file, string $kind, ?string $title, ?Document $replacing, ChangeSource $source): DocumentVersion
    {
        $contents = (string) file_get_contents($file->getRealPath() ?: $file->getPathname());
        $sha256 = hash('sha256', $contents);
        $mime = $this->mimeType($file);

        $version = DB::transaction(function () use ($student, $file, $kind, $title, $replacing, $source, $contents, $sha256, $mime) {
            $document = $replacing !== null
                ? Document::query()->lockForUpdate()->findOrFail($replacing->getKey())
                : Document::query()->create([
                    'user_id' => $student->getKey(),
                    'kind' => $kind,
                    'title' => $title !== null && trim($title) !== '' ? trim($title) : Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 200, ''),
                ]);

            $previous = $document->current_version_id !== null ? DocumentVersion::query()->find($document->current_version_id) : null;

            if ($previous !== null && hash_equals($previous->sha256, $sha256)) {
                throw ValidationException::withMessages(['file' => 'This file is identical to the current version.']);
            }

            $number = (int) DocumentVersion::query()->where('document_id', $document->getKey())->max('version') + 1;
            $id = (string) Str::ulid();
            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
            $path = "documents/{$student->getKey()}/{$document->getKey()}/{$id}.{$extension}";

            Storage::disk(self::DISK)->put($path, $contents);

            $version = DocumentVersion::query()->create([
                'id' => $id,
                'document_id' => $document->getKey(),
                'user_id' => $student->getKey(),
                'version' => $number,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime_type' => $mime,
                'size_bytes' => strlen($contents),
                'sha256' => $sha256,
                'status' => DocumentVersionStatus::Queued,
            ]);

            if ($previous !== null) {
                $previous->update(['status' => DocumentVersionStatus::Superseded]);
                $this->retireCandidates($previous);
            }

            $document->update(['current_version_id' => $version->id]);

            $this->audit->record((int) $student->getKey(), $source, $previous !== null ? 'document.replaced' : 'document.uploaded', $document, $previous?->version, $number, [
                'version' => [$previous?->id, $version->id],
            ], ['title' => $document->title, 'sha256' => $sha256]);

            return $version;
        });

        ProcessDocumentVersionJob::dispatch($version->id)->afterCommit();

        return $version;
    }

    /**
     * Try reading a failed current version again.
     */
    public function retry(DocumentVersion $version): void
    {
        $document = $version->document;

        if ($version->status !== DocumentVersionStatus::Failed || $document->current_version_id !== $version->id) {
            throw ValidationException::withMessages(['version' => 'Only the current version can be retried, and only after it failed.']);
        }

        $version->update(['status' => DocumentVersionStatus::Queued, 'failure_reason' => null]);

        ProcessDocumentVersionJob::dispatch($version->id);
    }

    private function retireCandidates(DocumentVersion $version): void
    {
        StudentFact::query()
            ->where('user_id', $version->user_id)
            ->where('review_state', StudentFact::REVIEW_PENDING)
            ->where('source_ref->document_version_id', $version->id)
            ->update(['review_state' => StudentFact::REVIEW_SUPERSEDED, 'superseded_at' => now()]);
    }

    private function mimeType(UploadedFile $file): string
    {
        return match (strtolower($file->getClientOriginalExtension())) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => (string) ($file->getMimeType() ?? 'application/octet-stream'),
        };
    }
}
