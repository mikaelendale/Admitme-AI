<?php

use App\Models\Application;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\CollegeListSchool;
use App\Models\DataExport;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// e2e helper (run via e2e-php.sh with the e2e environment). Usage:
//   inspect.php zip <path-to-zip>                    → JSON: entries, data.json profile revision, manifest snapshot
//   inspect.php export <export-id>                   → JSON: status, directory, archive exists
//   inspect.php expire <export-id>                   → sets expires_at to an hour ago
//   inspect.php document-files <user-email>          → JSON: stored document paths and whether they exist
require getenv('APP_PATH').'/vendor/autoload.php';
$app = require getenv('APP_PATH').'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_ends_with((string) config('database.connections.sqlite.database'), 'e2e.sqlite')) {
    fwrite(STDERR, "Refusing: not the e2e database\n");
    exit(1);
}

[$command, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];

switch ($command) {
    case 'zip':
        $zip = new ZipArchive;
        $zip->open($arg);
        $entries = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $data = json_decode((string) $zip->getFromName('data.json'), true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $hashesMatch = collect($manifest['included'] ?? [])->every(fn ($entry) => hash('sha256', (string) $zip->getFromName($entry['path'])) === $entry['sha256']);
        echo json_encode(['entries' => $entries, 'profile_revision' => $data['profile']['revision'] ?? null, 'snapshot' => $manifest['snapshot'] ?? null, 'hashes_match' => $hashesMatch]);
        break;
    case 'export':
        $export = DataExport::query()->find($arg);
        echo json_encode(['status' => $export?->status, 'directory' => $export?->directory, 'archive_exists' => $export?->archive_path !== null && Storage::disk('local')->exists($export->archive_path)]);
        break;
    case 'expire':
        DataExport::query()->whereKey($arg)->update(['expires_at' => now()->subHour()]);
        echo json_encode(['ok' => true]);
        break;
    case 'document-files':
        $user = User::query()->where('email', $arg)->firstOrFail();
        echo json_encode(DocumentVersion::query()->where('user_id', $user->id)->get(['path'])->map(fn ($v) => ['path' => $v->path, 'exists' => Storage::disk('local')->exists($v->path)])->all());
        break;
    case 'browser-snapshot':
        // The latest observation the extension sent for a session, exactly as stored.
        $session = BrowserSession::query()->findOrFail($arg);
        echo json_encode(['fields' => $session->latestSnapshot?->fields ?? [], 'raw' => json_encode($session->latestSnapshot?->getAttributes() ?? [])]);
        break;
    case 'browser-commands':
        echo json_encode(BrowserCommand::query()->where('browser_session_id', $arg)->orderBy('created_at')->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->payload['label'] ?? null, 'value' => $c->payload['value'] ?? null, 'status' => $c->status, 'attempts' => $c->attempts, 'observed' => $c->result['observed_value'] ?? null])->all());
        break;
    case 'college':
        // A saved college of the synthetic student by name: deadlines, status, notes and whether an application exists.
        $student = User::query()->where('email', 'synthetic.student@example.test')->firstOrFail();
        $school = CollegeListSchool::query()->where('user_id', $student->id)->where('school_name', $arg)->first();
        echo json_encode($school === null ? null : [
            'ea' => $school->ea_deadline?->format('Y-m-d'),
            'ed' => $school->ed_deadline?->format('Y-m-d'),
            'rd' => $school->rd_deadline?->format('Y-m-d'),
            'choice' => $school->choice_status?->value,
            'notes' => $school->student_notes,
            'revision' => $school->revision,
            'application' => Application::query()->where('college_list_school_id', $school->id)->exists(),
        ]);
        break;
    default:
        fwrite(STDERR, "Unknown command\n");
        exit(1);
}
