<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Domain\Knowledge\KnowledgeSearch;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Searches only the authenticated student's own uploaded documents. Results are document text, not
 * confirmed facts.
 */
class SearchMyDocumentsTool implements ReadOnlyTool, Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Search the text of the student\'s own uploaded documents (transcripts, test reports…). Returns matching passages with the document name and page. This is raw document text, not confirmed profile facts — say so if you rely on it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $hits = app(KnowledgeSearch::class)->privateDocumentPages($this->user, $request->string('query')->toString());

        return json_encode([
            'results' => $hits->map(fn (array $hit) => [
                'document' => $hit['page']->version->document->title,
                'page' => $hit['page']->page_number,
                'text' => Str::limit($hit['page']->text, 800, '…'),
            ])->values(),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Words to look for, e.g. "mathematics grade".')->required(),
        ];
    }
}
