<?php

namespace App\Domain\Privacy;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exports\ExportService;
use App\Domain\Memory\MemoryReader;
use App\Domain\Shared\ChangeSource;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\KnowledgeSource;
use App\Models\ResearchAnswer;
use App\Models\StudentFact;
use App\Models\User;
use App\Models\WritingPiece;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deleting something removes it everywhere it was copied: the original files, searchable text, derived
 * candidate values, answers that quote it, cached projections and any export archive that contains it.
 * Confirmed profile values the student approved stay — only the link to the deleted file is removed.
 */
final class DataLifecycleService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ExportService $exports,
        private readonly MemoryReader $memory,
    ) {}

    public function deleteDocument(User $student, Document $document): void
    {
        abort_unless((int) $document->user_id === (int) $student->getKey(), 404);

        $files = DocumentVersion::query()->where('document_id', $document->getKey())->get(['disk', 'path']);

        DB::transaction(function () use ($student, $document) {
            $facts = StudentFact::query()->where('user_id', $student->getKey())->where('source_ref->document_id', (string) $document->getKey())->get();

            foreach ($facts as $fact) {
                // A value only proposed by the document goes; a value the student confirmed stays, unlinked.
                if ($fact->review_state === StudentFact::REVIEW_PENDING) {
                    $fact->delete();
                } else {
                    $fact->forceFill(['source_ref' => ['document_deleted' => true]])->save();
                }
            }

            $this->purgeExportsContaining($student, 'document_versions', (string) $document->getKey(), 'It contained a document you deleted.');

            $this->audit->record((int) $student->getKey(), ChangeSource::studentManual($student, 'privacy.document.delete'), 'document.deleted', $document, null, null, [
                'title' => [$document->title, null],
            ]);

            // Versions and their searchable page text go with it (cascade).
            $document->delete();
        });

        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }
    }

    public function deleteWritingPiece(User $student, WritingPiece $piece): void
    {
        abort_unless((int) $piece->user_id === (int) $student->getKey(), 404);

        DB::transaction(function () use ($student, $piece) {
            $this->purgeExportsContaining($student, 'writing_versions', (string) $piece->getKey(), 'It contained writing you deleted.');
            $this->audit->record((int) $student->getKey(), ChangeSource::studentManual($student, 'privacy.writing.delete'), 'writing.deleted', $piece, null, null, ['title' => [$piece->title, null]]);

            // Versions and feedback go with it (cascade).
            $piece->delete();
        });
    }

    /**
     * A page the student added privately (shared official pages are not theirs to delete).
     */
    public function deletePrivateSource(User $student, KnowledgeSource $source): void
    {
        abort_unless((int) $source->owner_user_id === (int) $student->getKey(), 404);

        DB::transaction(function () use ($student, $source) {
            // Research answers carry quotes from the page as evidence: they go too.
            ResearchAnswer::query()
                ->where('user_id', $student->getKey())
                ->get()
                ->filter(fn (ResearchAnswer $answer) => collect($answer->evidence)->contains(fn ($e) => ($e['url'] ?? null) === $source->url))
                ->each->delete();

            $this->audit->record((int) $student->getKey(), ChangeSource::studentManual($student, 'privacy.source.delete'), 'knowledge_source.deleted', $source, null, null, ['url' => [$source->url, null]]);

            // Versions, passages and requirements from it go with it (cascade).
            $source->delete();
        });
    }

    /**
     * Before an account is deleted: remove what database cascades cannot reach — files on disk,
     * conversations (stored polymorphically), export archives and cached projections.
     */
    public function eraseAccountData(User $student): void
    {
        foreach (DataExport::query()->where('user_id', $student->getKey())->get() as $export) {
            Storage::disk($export->disk)->deleteDirectory($export->directory);
        }

        foreach (DocumentVersion::query()->where('user_id', $student->getKey())->get(['disk', 'path']) as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }

        // And the student's own storage folders, including anything left over from interrupted work.
        Storage::disk('local')->deleteDirectory("documents/{$student->getKey()}");
        Storage::disk('local')->deleteDirectory("exports/{$student->getKey()}");

        $conversations = DB::table('agent_conversations')
            ->where('participant_type', $student->getMorphClass())
            ->where('participant_id', $student->getKey())
            ->pluck('id');

        DB::table('agent_conversation_messages')->whereIn('conversation_id', $conversations)->delete();
        DB::table('agent_conversations')->whereIn('id', $conversations)->delete();

        $this->forgetCaches($student);
    }

    public function forgetCaches(User $student): void
    {
        $revision = $this->memory->profileRevision($student);
        $schema = (int) config('admitme.context.memory_schema_version', 1);

        foreach (MemoryReader::SECTIONS as $section) {
            foreach (range(max(0, $revision - 5), $revision) as $r) {
                Cache::forget("admitme:memory:{$student->getKey()}:{$section}:r{$r}:s{$schema}");
            }
        }
    }

    private function purgeExportsContaining(User $student, string $snapshotKey, string $id, string $reason): void
    {
        DataExport::query()
            ->where('user_id', $student->getKey())
            ->whereIn('status', ['queued', 'building', 'ready'])
            ->get()
            ->filter(fn (DataExport $export) => array_key_exists($id, (array) ($export->snapshot[$snapshotKey] ?? [])))
            ->each(fn (DataExport $export) => $this->exports->purge($export, 'deleted', $reason));
    }
}
