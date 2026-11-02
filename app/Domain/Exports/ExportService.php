<?php

namespace App\Domain\Exports;

use App\Domain\Applications\ApplicationWorkspace;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Memory\MemoryReader;
use App\Domain\Tasks\TaskPresenter;
use App\Jobs\BuildExportJob;
use App\Models\AgentTask;
use App\Models\Application;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\WritingFeedback;
use App\Models\WritingPiece;
use App\Models\WritingVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use ZipArchive;

/**
 * Exports a student's own data. The mutable records are read in ONE transaction when the export is
 * requested and written to a private snapshot file; documents and writing are referenced by immutable
 * version ids. The queued build only packages that snapshot, so an archive never mixes data from before
 * and after an edit made while it was being built.
 */
final class ExportService
{
    /** Never included, whatever is selected. */
    public const EXCLUDED = [
        'Passwords, two-factor secrets and login sessions',
        'Billing and payment identifiers',
        'AI provider keys, internal tokens and browser cookies',
        'Other students\' data and the shared college knowledge base',
    ];

    public function __construct(
        private readonly MemoryReader $memory,
        private readonly ApplicationWorkspace $workspace,
    ) {}

    /**
     * @param  array<string, mixed>  $selection  sections, document_ids, writing_piece_ids
     */
    public function request(User $student, array $selection, string $via): DataExport
    {
        $validated = Validator::make($selection, [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => [Rule::in(DataExport::SECTIONS)],
            'document_ids' => ['nullable', 'array', 'max:100'],
            'document_ids.*' => ['string', 'max:26'],
            'writing_piece_ids' => ['nullable', 'array', 'max:100'],
            'writing_piece_ids.*' => ['string', 'max:26'],
        ])->validate();

        $id = Str::lower((string) Str::ulid());
        $directory = "exports/{$student->getKey()}/{$id}";

        $export = DB::transaction(function () use ($student, $validated, $via, $id, $directory) {
            [$payload, $snapshot, $omitted] = $this->capture($student, $validated);

            Storage::disk('local')->put("{$directory}/snapshot.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return DataExport::query()->create([
                'id' => $id,
                'user_id' => $student->getKey(),
                'status' => 'queued',
                'selection' => $validated,
                'schema_version' => DataExport::SCHEMA_VERSION,
                'snapshot' => $snapshot,
                'requested_via' => $via,
                'disk' => 'local',
                'directory' => $directory,
                'manifest' => ['omitted' => $omitted],
            ]);
        });

        BuildExportJob::dispatch((string) $export->getKey());

        return $export;
    }

    /**
     * Package the captured snapshot and the referenced immutable versions into a private ZIP.
     */
    public function build(DataExport $export): void
    {
        if (! in_array($export->status, ['queued', 'building'], true)) {
            return;
        }

        $export->update(['status' => 'building']);
        $disk = Storage::disk($export->disk);
        $payload = json_decode((string) $disk->get("{$export->directory}/snapshot.json"), true);

        if (! is_array($payload)) {
            $export->update(['status' => 'failed', 'status_reason' => 'The captured snapshot is missing.']);

            return;
        }

        $archive = "{$export->directory}/admitme-export.zip";
        $zip = new ZipArchive;

        if ($zip->open($disk->path($archive), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }

        $included = [];
        $omitted = (array) ($export->manifest['omitted'] ?? []);
        $add = function (string $path, string $contents, string $type, ?string $id = null, ?int $version = null) use ($zip, &$included): void {
            $zip->addFromString($path, $contents);
            $included[] = array_filter(['type' => $type, 'id' => $id, 'version' => $version, 'path' => $path, 'sha256' => hash('sha256', $contents)], fn ($v) => $v !== null);
        };

        $add('data.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'data');

        if (isset($payload['college_list'])) {
            $add('college-list.csv', $this->csv(['College', 'List status', 'Tier', 'Cycle', 'Deadlines', 'Portal', 'Revision'], array_map(fn (array $c) => [$c['name'], $c['list_status'], $c['tier'], $c['cycle'], implode('; ', $c['deadlines']), $c['portal_url'], $c['revision']], $payload['college_list'])), 'csv');
        }

        if (isset($payload['applications'])) {
            $rows = [];
            foreach ($payload['applications'] as $application) {
                foreach ($application['checklist'] as $item) {
                    $rows[] = [$application['college'], $item['title'], $item['owner'], $item['status'], $item['due'] ?? '', $item['source'] ?? ''];
                }
            }
            $add('application-checklists.csv', $this->csv(['College', 'Step', 'Done by', 'Status', 'Due', 'Source'], $rows), 'csv');
        }

        if (isset($payload['tasks'])) {
            $rows = [];
            foreach ($payload['tasks'] as $goal) {
                foreach ($goal['steps'] as $step) {
                    $rows[] = [$goal['goal'], $step['step'], $step['status'], $step['result'] ?? ''];
                }
            }
            $add('tasks.csv', $this->csv(['Plan', 'Step', 'Status', 'Result'], $rows), 'csv');
        }

        foreach ((array) ($export->snapshot['writing_versions'] ?? []) as $pieceId => $versionId) {
            $version = WritingVersion::query()->find($versionId);

            if ($version === null) {
                $omitted[] = ['type' => 'writing', 'id' => $pieceId, 'reason' => 'Deleted before the export was built.'];

                continue;
            }

            $name = 'writing/'.Str::slug($version->piece?->title ?? 'piece').'-v'.$version->number;
            $add("{$name}.txt", $version->body, 'writing_version', (string) $version->id, $version->number);

            $feedback = WritingFeedback::query()->where('writing_version_id', $version->getKey())->get(['summary', 'comments', 'assistance_policy', 'model', 'created_at']);
            if ($feedback->isNotEmpty()) {
                $add("{$name}-feedback.json", json_encode($feedback->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'writing_feedback', (string) $version->id, $version->number);
            }
        }

        foreach ((array) ($export->snapshot['document_versions'] ?? []) as $documentId => $versionId) {
            $version = DocumentVersion::query()->with('document')->find($versionId);

            if ($version === null || ! Storage::disk($version->disk)->exists($version->path)) {
                $omitted[] = ['type' => 'document', 'id' => $documentId, 'reason' => 'Deleted before the export was built.'];

                continue;
            }

            $contents = Storage::disk($version->disk)->get($version->path);

            // Only the exact file that was recorded goes in.
            if (hash('sha256', $contents) !== $version->sha256) {
                $omitted[] = ['type' => 'document', 'id' => $documentId, 'reason' => 'The stored file did not match its recorded checksum.'];

                continue;
            }

            $extension = pathinfo($version->original_name, PATHINFO_EXTENSION) ?: 'bin';
            $add('documents/'.Str::slug($version->document?->title ?? 'document').'-v'.$version->version.'.'.$extension, $contents, 'document_version', (string) $version->id, (int) $version->version);
        }

        $manifest = [
            'schema_version' => $export->schema_version,
            'captured_at' => $export->snapshot['captured_at'] ?? null,
            'built_at' => now()->toIso8601String(),
            'snapshot' => $export->snapshot,
            'included' => $included,
            'omitted' => $omitted,
            'never_included' => self::EXCLUDED,
        ];

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('README.txt', "AdmitMe export (schema {$export->schema_version}).\nCaptured {$manifest['captured_at']}: everything in data.json and the CSV files reflects your records at that moment.\nDocuments and writing are the exact saved versions listed in manifest.json (with SHA-256 checksums).\nNever included: ".implode('; ', self::EXCLUDED).".\n");
        $zip->close();

        $disk->delete("{$export->directory}/snapshot.json");

        $export->update([
            'status' => 'ready',
            'archive_path' => $archive,
            'archive_size' => $disk->size($archive),
            'archive_sha256' => hash_file('sha256', $disk->path($archive)),
            'manifest' => $manifest,
            'ready_at' => now(),
            'expires_at' => now()->addHours((int) config('admitme.exports.expires_after_hours', 24)),
        ]);
    }

    /**
     * Delete an export's files now and keep the record (with why) for the student's history.
     */
    public function purge(DataExport $export, string $status, string $reason): void
    {
        Storage::disk($export->disk)->deleteDirectory($export->directory);
        $export->update(['status' => $status, 'status_reason' => $reason, 'archive_path' => null]);
    }

    /**
     * Read everything selected in one consistent pass.
     *
     * @param  array<string, mixed>  $selection
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<array<string, string>>}
     */
    private function capture(User $student, array $selection): array
    {
        $sections = $selection['sections'];
        $omitted = [];
        $snapshot = ['captured_at' => now()->toIso8601String(), 'profile_revision' => $this->memory->profileRevision($student)];
        $payload = ['schema_version' => DataExport::SCHEMA_VERSION, 'exported_at' => $snapshot['captured_at'], 'student' => ['name' => $student->name, 'email' => $student->email]];

        if (in_array('profile', $sections, true)) {
            $payload['profile'] = ['revision' => $snapshot['profile_revision'], 'sections' => $this->memory->profile($student)];
        }

        if (in_array('college_list', $sections, true)) {
            $payload['college_list'] = $this->memory->collegeList($student, [CollegeChoiceStatus::Suggested, CollegeChoiceStatus::Shortlisted, CollegeChoiceStatus::Applying]);
            $snapshot['college_list_revisions'] = array_column($payload['college_list'], 'revision', 'id');
        }

        if (in_array('applications', $sections, true)) {
            $payload['applications'] = Application::query()->where('user_id', $student->getKey())->get()->map(function (Application $application) {
                $full = $this->workspace->for($application);

                return [
                    'id' => (string) $application->id,
                    'college' => $full['college']['name'],
                    'status' => $full['application']['statusLabel'],
                    'revision' => $full['application']['revision'],
                    'deadline' => $full['deadline'],
                    'checklist' => array_map(fn (array $i) => ['title' => $i['title'], 'owner' => $i['owner'] === 'third_party' ? ($i['ownerLabel'] ?? 'someone else') : 'student', 'status' => $i['statusLabel'], 'how_we_know' => $i['completionBasis'], 'due' => $i['dueDate'], 'source' => $i['source']['url'] ?? null], $full['checklist']),
                    // Every value the browser extension was approved to type into this college's form, and what it read back.
                    'browser_fills' => BrowserCommand::query()
                        ->where('user_id', $application->user_id)
                        ->whereIn('browser_session_id', BrowserSession::query()->where('application_id', $application->getKey())->select('id'))
                        ->orderBy('created_at')
                        ->get()
                        ->map(fn (BrowserCommand $c) => ['field' => $c->payload['label'] ?? null, 'value' => $c->payload['value'] ?? null, 'status' => $c->status, 'read_back' => $c->result['observed_value'] ?? null, 'site' => $c->origin, 'at' => ($c->completed_at ?? $c->created_at)?->toIso8601String()])
                        ->values()
                        ->all(),
                ];
            })->values()->all();
            $snapshot['application_revisions'] = array_column($payload['applications'], 'revision', 'id');
        }

        if (in_array('tasks', $sections, true)) {
            $payload['tasks'] = AgentTask::query()->where('user_id', $student->getKey())->whereNull('parent_id')->latest('created_at')->get()->map(fn (AgentTask $goal) => TaskPresenter::forMentor($goal))->values()->all();
        }

        if (in_array('writing', $sections, true)) {
            $snapshot['writing_versions'] = [];
            $payload['writing'] = [];

            foreach (array_unique((array) ($selection['writing_piece_ids'] ?? [])) as $pieceId) {
                $piece = WritingPiece::query()->where('user_id', $student->getKey())->find($pieceId);

                if ($piece === null || $piece->current_version_id === null) {
                    $omitted[] = ['type' => 'writing', 'id' => (string) $pieceId, 'reason' => $piece === null ? 'Not found among your writing.' : 'No saved version yet.'];

                    continue;
                }

                $snapshot['writing_versions'][(string) $piece->id] = (string) $piece->current_version_id;
                $payload['writing'][] = ['id' => (string) $piece->id, 'title' => $piece->title, 'prompt' => $piece->prompt_text, 'word_limit' => $piece->word_limit, 'policy' => $piece->assistance_policy->value, 'version_id' => (string) $piece->current_version_id];
            }
        }

        if (in_array('documents', $sections, true)) {
            $snapshot['document_versions'] = [];
            $payload['documents'] = [];

            foreach (array_unique((array) ($selection['document_ids'] ?? [])) as $documentId) {
                $document = Document::query()->where('user_id', $student->getKey())->find($documentId);
                $version = $document?->current_version_id !== null ? DocumentVersion::query()->find($document->current_version_id) : null;

                if ($document === null || $version === null) {
                    $omitted[] = ['type' => 'document', 'id' => (string) $documentId, 'reason' => $document === null ? 'Not found among your documents.' : 'No stored version.'];

                    continue;
                }

                $snapshot['document_versions'][(string) $document->id] = (string) $version->id;
                $payload['documents'][] = ['id' => (string) $document->id, 'title' => $document->title, 'kind' => $document->kind, 'version' => (int) $version->version, 'version_id' => (string) $version->id, 'sha256' => $version->sha256, 'original_name' => $version->original_name];
            }
        }

        return [$payload, $snapshot, $omitted];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, escape: '');

        foreach ($rows as $row) {
            // Neutralise spreadsheet formulas in exported text.
            fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value, $row), escape: '');
        }

        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
