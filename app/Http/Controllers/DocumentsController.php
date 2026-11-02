<?php

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentService;
use App\Domain\Documents\DocumentTextExtractor;
use App\Domain\Documents\DocumentVersionStatus;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Domain\Students\StudentFieldRegistry;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OnboardingProfile;
use App\Models\StudentFact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentsController extends Controller
{
    private const MAX_UPLOAD_KB = 10240;

    public function index(Request $request, DocumentTextExtractor $extractor): Response
    {
        $user = $request->user();

        $pending = StudentFact::query()
            ->where('user_id', $user->getKey())
            ->where('review_state', StudentFact::REVIEW_PENDING)
            ->get(['source_ref'])
            ->countBy(fn (StudentFact $fact) => $fact->source_ref['document_id'] ?? '');

        $documents = Document::query()
            ->where('user_id', $user->getKey())
            ->with('currentVersion')
            ->latest('updated_at')
            ->get()
            ->map(fn (Document $document) => [
                'id' => (string) $document->id,
                'title' => $document->title,
                'kind' => $document->kind,
                'kindLabel' => Document::KINDS[$document->kind] ?? $document->kind,
                'version' => $document->currentVersion?->version,
                'status' => $document->currentVersion?->status->value,
                'statusLabel' => $document->currentVersion?->status->label(),
                'failureReason' => $document->currentVersion?->failure_reason,
                'uploadedAt' => $document->currentVersion?->created_at?->toIso8601String(),
                'pendingValues' => (int) ($pending[$document->id] ?? 0),
            ])
            ->values()
            ->all();

        return Inertia::render('documents', [
            'documents' => $documents,
            'kinds' => Document::KINDS,
            'ocrAvailable' => $extractor->ocrAvailable(),
            'maxUploadMb' => intdiv(self::MAX_UPLOAD_KB, 1024),
        ]);
    }

    /**
     * The student's documents for the mentor composer: name, type, size, pages, reading status and the
     * opening lines of the text, so a file can be attached to a message (and checked before sending).
     */
    public function attachable(Request $request): JsonResponse
    {
        $documents = Document::query()
            ->where('user_id', $request->user()->getKey())
            ->with(['currentVersion.pages' => fn ($query) => $query->where('page_number', 1)])
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Document $document) => $this->attachablePayload($document))
            ->values();

        return response()->json(['documents' => $documents]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attachablePayload(Document $document): array
    {
        $version = $document->currentVersion;
        $firstPage = $version?->pages->first();

        return [
            'id' => (string) $document->id,
            'title' => $document->title,
            'name' => $version?->original_name ?? $document->title,
            'kind' => $document->kind,
            'kindLabel' => Document::KINDS[$document->kind] ?? $document->kind,
            'mime' => $version?->mime_type,
            'sizeBytes' => $version?->size_bytes,
            'pages' => $version?->page_count,
            'status' => $version?->status->value,
            'statusLabel' => $version?->status->label(),
            'failureReason' => $version?->failure_reason,
            'preview' => $firstPage !== null ? Str::limit(trim((string) $firstPage->text), 420, '…') : null,
        ];
    }

    public function store(Request $request, DocumentService $documents): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', $this->fileRule()],
            'kind' => ['required', Rule::in(array_keys(Document::KINDS))],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $version = $documents->upload(
            $request->user(),
            $request->file('file'),
            $validated['kind'],
            $validated['title'] ?? null,
            null,
            ChangeSource::studentManual($request->user(), 'documents.store'),
        );

        // The mentor composer uploads in place and attaches the new document to the next message.
        if ($request->wantsJson()) {
            $document = Document::query()->with(['currentVersion.pages' => fn ($query) => $query->where('page_number', 1)])->findOrFail($version->document_id);

            return response()->json(['document' => $this->attachablePayload($document)], 201);
        }

        return redirect()->route('documents.show', $version->document_id);
    }

    public function storeVersion(Request $request, Document $document, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('update', $document);

        $request->validate(['file' => ['required', $this->fileRule()]]);

        $documents->upload(
            $request->user(),
            $request->file('file'),
            $document->kind,
            null,
            $document,
            ChangeSource::studentManual($request->user(), 'documents.versions.store'),
        );

        return redirect()->route('documents.show', $document);
    }

    public function show(Request $request, Document $document): Response
    {
        Gate::authorize('view', $document);

        $document->load(['versions', 'currentVersion.pages']);
        $current = $document->currentVersion;

        $values = StudentFact::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('status', [FactStatus::Candidate, FactStatus::Conflict])
            ->where('source_ref->document_id', $document->id)
            ->orderBy('id')
            ->get();

        $profile = OnboardingProfile::query()->where('user_id', $request->user()->getKey())->first();

        return Inertia::render('documents/show', [
            'document' => [
                'id' => (string) $document->id,
                'title' => $document->title,
                'kindLabel' => Document::KINDS[$document->kind] ?? $document->kind,
                'currentVersionId' => $current?->id,
            ],
            'versions' => $document->versions->map(fn (DocumentVersion $version) => [
                'id' => (string) $version->id,
                'version' => $version->version,
                'originalName' => $version->original_name,
                'status' => $version->status->value,
                'statusLabel' => $version->status->label(),
                'failureReason' => $version->failure_reason,
                'sizeKb' => (int) ceil($version->size_bytes / 1024),
                'sha256' => substr($version->sha256, 0, 12),
                'uploadedAt' => $version->created_at?->toIso8601String(),
                'isCurrent' => $version->id === $current?->id,
                'canRetry' => $version->id === $current?->id && $version->status === DocumentVersionStatus::Failed,
            ])->values()->all(),
            'pages' => $current?->pages->map(fn ($page) => ['number' => $page->page_number, 'text' => $page->text])->values()->all() ?? [],
            'values' => $values->map(fn (StudentFact $fact) => [
                'id' => $fact->id,
                'field' => $fact->field_key,
                'label' => StudentFieldRegistry::has($fact->field_key) ? StudentFieldRegistry::get($fact->field_key)['label'] : $fact->field_key,
                'value' => is_scalar($fact->value) ? (string) $fact->value : json_encode($fact->value),
                'status' => $fact->status->value,
                'reviewState' => $fact->review_state,
                'page' => $fact->source_ref['page'] ?? null,
                'quote' => $fact->source_ref['quote'] ?? null,
                'versionId' => $fact->source_ref['document_version_id'] ?? null,
                'ambiguous' => (bool) ($fact->source_ref['ambiguous'] ?? false),
                'note' => $fact->note,
                'profileValue' => $this->display($profile?->getAttribute(StudentFieldRegistry::column($fact->field_key))),
            ])->values()->all(),
            'profileRevision' => (int) ($profile?->revision ?? 0),
        ]);
    }

    public function file(Request $request, Document $document, DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('view', $document);
        abort_unless($version->document_id === $document->id, 404);

        return Storage::disk($version->disk)->download($version->path, $version->original_name);
    }

    public function retry(Request $request, Document $document, DocumentVersion $version, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('update', $document);
        abort_unless($version->document_id === $document->id, 404);

        $documents->retry($version);

        return redirect()->route('documents.show', $document);
    }

    private function fileRule(): File
    {
        return File::types(['pdf', 'docx', 'txt', 'png', 'jpg', 'jpeg'])->max(self::MAX_UPLOAD_KB);
    }

    private function display(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => null,
            is_array($value) => implode(', ', array_map('strval', $value)),
            default => (string) $value,
        };
    }
}
