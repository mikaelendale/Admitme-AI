<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Domain\Approvals\DocumentValueProposals;
use App\Domain\Students\StudentFieldRegistry;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The values read from the student's uploads that wait for their review — the same list the Documents page
 * shows. Read-only (available in Ask mode); the values are candidates, never facts.
 */
class ReadPendingDocumentValuesTool implements ReadOnlyTool, Tool
{
    private const LIMIT = 60;

    public function __construct(protected User $student) {}

    public function description(): Stringable|string
    {
        return 'Read the values AdmitMe read from the student\'s uploaded documents (transcripts, score reports…) that are waiting for the student\'s review: for each, its id, the profile field, the value as read, the value on their profile now, the document, page and the exact quote. '
            .'These are NOT confirmed facts — they reach the profile only when the student accepts them — so never present them as the student\'s data, and point out values whose quote looks unclear or that differ from the profile. '
            .'Pass part of a document title to see one document\'s values, or null for all. In Agent mode, ProposeDocumentValueDecisionsTool proposes accepting or rejecting them (it takes the ids from here; never show ids to the student).';
    }

    public function handle(Request $request): Stringable|string
    {
        $filter = is_string($request['document'] ?? null) ? mb_strtolower(trim($request['document'])) : '';
        $profile = OnboardingProfile::query()->where('user_id', $this->student->getKey())->first();

        $rows = app(DocumentValueProposals::class)->pending($this->student)
            ->filter(fn (array $row) => $filter === '' || str_contains(mb_strtolower((string) $row['document']->title), $filter))
            ->values();

        return json_encode([
            'values' => $rows->take(self::LIMIT)->map(fn (array $row) => [
                'id' => (int) $row['fact']->getKey(),
                'field' => $row['fact']->field_key,
                'label' => $row['label'],
                'extracted_value' => self::shown($row['fact']->value),
                'profile_value' => self::shown($profile?->getAttribute(StudentFieldRegistry::column($row['fact']->field_key))),
                'document' => $row['document']->title,
                'page' => $row['page'],
                'quote' => $row['quote'],
                ...(($row['fact']->source_ref['ambiguous'] ?? false) ? ['ambiguous' => true] : []),
                ...(is_string($row['fact']->note) && $row['fact']->note !== '' ? ['note' => $row['fact']->note] : []),
            ])->all(),
            'waiting_total' => $rows->count(),
            'message' => $rows->isEmpty()
                ? 'No values from the student\'s documents are waiting for review.'
                : 'Not confirmed: these reach the profile only when the student accepts them (on the Documents page, or by approving a proposal in Agent mode).',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document' => $schema->string()
                ->description('Only values from documents whose title contains this text (e.g. "transcript"), or null for every document.')
                ->nullable()
                ->required(),
        ];
    }

    private static function shown(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => null,
            is_array($value) => implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : (json_encode($item) ?: ''), $value)),
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
