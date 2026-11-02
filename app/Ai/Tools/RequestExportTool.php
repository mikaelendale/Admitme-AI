<?php

namespace App\Ai\Tools;

use App\Domain\Exports\ExportService;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\User;
use App\Models\WritingPiece;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: start an export of the student's own data (the same service as the "Your data" page).
 */
class RequestExportTool implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Start an export of the student\'s own data as a ZIP (profile, college list, applications with checklists, tasks, and optionally their documents and writing). It is built in the background and appears on the "Your data" page, where only they can download it until the link expires (24 hours). Never paste data instead of exporting it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $sections = array_values(array_intersect((array) ($request['sections'] ?? []), DataExport::SECTIONS));

        try {
            $export = app(ExportService::class)->request($this->user, [
                'sections' => $sections,
                // "All of mine" is resolved here, for this student only.
                'document_ids' => in_array('documents', $sections, true) ? Document::query()->where('user_id', $this->user->getKey())->pluck('id')->map(fn ($id) => (string) $id)->all() : [],
                'writing_piece_ids' => in_array('writing', $sections, true) ? WritingPiece::query()->where('user_id', $this->user->getKey())->pluck('id')->map(fn ($id) => (string) $id)->all() : [],
            ], 'chat');
        } catch (ValidationException $e) {
            return json_encode(['status' => 'invalid', 'errors' => $e->errors()]) ?: '{}';
        }

        return json_encode([
            'status' => 'export_requested',
            'export_id' => (string) $export->getKey(),
            'sections' => $sections,
            'note' => 'Being built now; it will be on the "Your data" page (/exports) to download, and the link expires after 24 hours.',
        ]) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sections' => $schema->array()->items($schema->string()->enum(DataExport::SECTIONS))->description('What to include.')->required(),
        ];
    }
}
