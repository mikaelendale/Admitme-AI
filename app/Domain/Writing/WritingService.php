<?php

namespace App\Domain\Writing;

use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Shared\StaleRevisionException;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use App\Models\User;
use App\Models\WritingPiece;
use App\Models\WritingVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The student's writing: pieces with their prompt, word limit and the college's assistance policy, and
 * immutable versions that only the student writes.
 */
final class WritingService
{
    public function __construct(private readonly InstitutionResolver $institutions) {}

    /**
     * @param  array<string, mixed>  $data  title, prompt_text?, word_limit?, kind?
     */
    public function createPiece(User $student, array $data, ?CollegeListSchool $school = null, ?ApplicationItem $item = null): WritingPiece
    {
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'prompt_text' => ['nullable', 'string', 'max:4000'],
            'word_limit' => ['nullable', 'integer', 'min:10', 'max:5000'],
            'kind' => ['nullable', Rule::in(WritingPiece::KINDS)],
        ])->validate();

        return WritingPiece::query()->create([
            'user_id' => $student->getKey(),
            'college_list_school_id' => $school?->getKey(),
            'application_item_id' => $item?->getKey(),
            'title' => trim($validated['title']),
            'prompt_text' => isset($validated['prompt_text']) ? trim($validated['prompt_text']) : null,
            'word_limit' => $validated['word_limit'] ?? null,
            'kind' => $validated['kind'] ?? ($school !== null ? 'supplement' : 'personal_statement'),
            'status' => 'draft',
            'assistance_policy' => $this->policyFor($school),
        ]);
    }

    /**
     * Start (or reopen) the piece for an essay step of an application, taking the prompt from its source.
     */
    public function forEssayStep(ApplicationItem $item): WritingPiece
    {
        $existing = WritingPiece::query()->where('application_item_id', $item->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        $school = $item->application->collegeListSchool;
        $prompt = $item->details !== null && preg_match('/Prompt:\s*(.+)/s', $item->details, $m) === 1 ? trim(strtok($m[1], "\n")) : $item->details;

        return $this->createPiece($item->application->user, [
            'title' => Str::limit(preg_replace('/^Write:\s*/', '', $item->title) ?: $item->title, 200, ''),
            'prompt_text' => $prompt,
            'word_limit' => $prompt !== null && preg_match('/(\d{2,4})\s*words?/i', $prompt, $w) === 1 ? (int) $w[1] : null,
            'kind' => 'supplement',
        ], $school, $item);
    }

    /**
     * Save the student's text as a new immutable version (unchanged text returns the current version).
     *
     * @throws StaleRevisionException when the editor was based on an older version (e.g. another tab).
     */
    public function saveVersion(WritingPiece $piece, User $student, string $body, ?int $basedOn): WritingVersion
    {
        Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:50000']])->validate();

        return DB::transaction(function () use ($piece, $student, $body, $basedOn) {
            /** @var WritingPiece $locked */
            $locked = WritingPiece::query()->lockForUpdate()->findOrFail($piece->getKey());
            $current = $locked->current_version_id !== null ? WritingVersion::query()->find($locked->current_version_id) : null;
            $currentNumber = $current?->number ?? 0;

            if ($basedOn !== null && $basedOn !== $currentNumber) {
                throw new StaleRevisionException('draft', $basedOn, $currentNumber);
            }

            $hash = hash('sha256', $body);

            if ($current !== null && $current->body_hash === $hash) {
                return $current;
            }

            $version = WritingVersion::query()->create([
                'writing_piece_id' => $locked->getKey(),
                'user_id' => $student->getKey(),
                'number' => $currentNumber + 1,
                'body' => $body,
                'word_count' => self::wordCount($body),
                'author' => 'student',
                'body_hash' => $hash,
            ]);

            $locked->forceFill(['current_version_id' => $version->getKey()])->save();

            return $version;
        });
    }

    public static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function policyFor(?CollegeListSchool $school): WritingPolicy
    {
        $institution = $school !== null ? $this->institutions->findForSchool($school) : null;

        return WritingPolicy::tryFrom((string) $institution?->writing_policy) ?? WritingPolicy::default();
    }
}
