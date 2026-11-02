<?php

namespace App\Http\Controllers;

use App\Domain\Exports\ExportService;
use App\Domain\Privacy\DataLifecycleService;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\KnowledgeSource;
use App\Models\WritingPiece;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Your data": exports the student can download until they expire, and deletion that reaches derived copies.
 */
class DataExportsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('exports', [
            'exports' => DataExport::query()->where('user_id', $user->getKey())->latest('created_at')->limit(20)->get()->map(fn (DataExport $export) => [
                'id' => (string) $export->id,
                'status' => $export->status,
                'statusReason' => $export->status_reason,
                'sections' => $export->selection['sections'] ?? [],
                'requestedVia' => $export->requested_via,
                'createdAt' => $export->created_at?->toIso8601String(),
                'expiresAt' => $export->expires_at?->toIso8601String(),
                'size' => $export->archive_size,
                'included' => count((array) ($export->manifest['included'] ?? [])),
                'omitted' => array_values((array) ($export->manifest['omitted'] ?? [])),
                // A signed link that stops working when the export expires (and still requires this account).
                'downloadUrl' => $export->isDownloadable() ? URL::temporarySignedRoute('exports.download', $export->expires_at, ['export' => $export->id]) : null,
            ])->values()->all(),
            'documents' => Document::query()->where('user_id', $user->getKey())->orderBy('title')->get(['id', 'title'])->map(fn (Document $d) => ['id' => (string) $d->id, 'title' => $d->title])->all(),
            'writing' => WritingPiece::query()->where('user_id', $user->getKey())->whereNotNull('current_version_id')->orderBy('title')->get(['id', 'title'])->map(fn (WritingPiece $p) => ['id' => (string) $p->id, 'title' => $p->title])->all(),
            'excluded' => ExportService::EXCLUDED,
        ]);
    }

    public function store(Request $request, ExportService $exports): RedirectResponse
    {
        $exports->request($request->user(), $request->only(['sections', 'document_ids', 'writing_piece_ids']), 'workspace');

        return back()->with('status', 'export-requested');
    }

    public function download(Request $request, DataExport $export): StreamedResponse
    {
        abort_unless((int) $export->user_id === (int) $request->user()->getKey(), 404);
        abort_unless($export->isDownloadable() && $export->archive_path !== null, 410, 'This export has expired or was deleted. Request a new one.');

        $export->increment('download_count');

        return Storage::disk($export->disk)->download($export->archive_path, 'admitme-export-'.$export->created_at?->format('Y-m-d').'.zip');
    }

    public function destroy(Request $request, DataExport $export, ExportService $exports): RedirectResponse
    {
        abort_unless((int) $export->user_id === (int) $request->user()->getKey(), 404);
        $exports->purge($export, 'deleted', 'Deleted by you.');

        return back()->with('status', 'export-deleted');
    }

    public function destroyDocument(Request $request, Document $document, DataLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->deleteDocument($request->user(), $document);

        return redirect()->route('documents.index')->with('status', 'document-deleted');
    }

    public function destroyWriting(Request $request, WritingPiece $piece, DataLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->deleteWritingPiece($request->user(), $piece);

        return redirect()->route('writing.index')->with('status', 'writing-deleted');
    }

    public function destroySource(Request $request, KnowledgeSource $source, DataLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->deletePrivateSource($request->user(), $source);

        return back()->with('status', 'source-deleted');
    }
}
