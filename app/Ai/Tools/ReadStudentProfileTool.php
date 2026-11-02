<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Domain\Memory\MemoryReader;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Bounded read of one section of the authenticated student's saved profile, with provenance.
 * The student comes from the run, never from the model.
 */
class ReadStudentProfileTool implements ReadOnlyTool, Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Read one section of the student\'s saved profile with where each value came from (confirmed by the student, AI-extracted and unconfirmed, or unknown) and which fields are missing. Sections: academics, identity, preferences, narrative, experience — or "all". "identity" and "all" also give the name and email on their AdmitMe account.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reader = app(MemoryReader::class);
        $section = $request->string('section')->toString();

        $payload = $section === 'all'
            ? ['profile_revision' => $reader->profileRevision($this->user), 'sections' => $reader->profile($this->user)]
            : (in_array($section, MemoryReader::SECTIONS, true)
                ? $reader->profileSection($this->user, $section)
                : ['error' => 'section must be one of: all, '.implode(', ', MemoryReader::SECTIONS)]);

        // Who the student is on their AdmitMe account: forms ask for a name and an email first.
        if ($section === 'all' || $section === 'identity') {
            $payload['account'] = [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'source' => 'The student\'s AdmitMe account. Use them for a form\'s name and email fields unless the student gave other values; ask if a form needs a legal name that may differ.',
            ];
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()
                ->enum(['all', ...MemoryReader::SECTIONS])
                ->description('Which part of the profile to read.')
                ->required(),
        ];
    }
}
