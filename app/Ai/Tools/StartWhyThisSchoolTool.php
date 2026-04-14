<?php

namespace App\Ai\Tools;

use App\Models\CollegeListSchool;
use App\Models\User;
use App\Services\Essay\WhyThisSchoolService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Generates a Why This School draft for a specific school on the student's list.
 */
class StartWhyThisSchoolTool implements Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Generate a **Why This School** essay first draft for one school on the student's saved college list.
Uses the school's stored data (programs, location, deadlines) and the student's profile to write a
school-specific draft with concrete hooks.

**Hard requirements — do not skip:**
1. Call **GetStudentCollegeList** first to get the school's `id`.
2. The school **must** exist as an active row on the list. If it's not there, explain that the draft
   uses saved school data and offer to add the school first.
3. Copy the `id` **verbatim** from GetStudentCollegeList. Never guess.

On success, the Why This School card appears below your message automatically.

Parameters: `college_list_school_id`.
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $schoolId = trim($request->string('college_list_school_id')->toString());
            if ($schoolId === '') {
                return json_encode([
                    'ok' => false,
                    'error' => 'college_list_school_id is required.',
                    'retryable' => false,
                    'suggestion' => 'Get the school id from GetStudentCollegeList.',
                ], JSON_UNESCAPED_UNICODE);
            }

            $school = CollegeListSchool::query()
                ->where('user_id', $this->user->id)
                ->where('is_removed', false)
                ->where('id', $schoolId)
                ->first();

            if ($school === null) {
                return json_encode([
                    'ok' => false,
                    'error' => 'School not found on this student\'s active list. Call GetStudentCollegeList and use the id from a real row.',
                    'retryable' => false,
                    'suggestion' => 'Add the school to the list first, then retry.',
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }

            $service = app(WhyThisSchoolService::class);
            $result = $this->runWhySchoolWithRetry(fn () => $service->generate($this->user, $schoolId));

            $payload = $result['payload'];
            $draft = (string) ($payload['draft'] ?? '');
            $hooksUsed = is_array($payload['hooks_used'] ?? null) ? $payload['hooks_used'] : [];
            $draftPreview = Str::limit($draft, 150, '...');
            $hooksJoined = implode(', ', array_slice($hooksUsed, 0, 4));

            return json_encode([
                'ok' => true,
                'why_school_ready' => true,
                'draft_id' => $result['id'],
                'school_name' => $school->school_name,
                'draft_preview' => $draftPreview,
                'hooks_used' => $hooksUsed,
                'chat_response' => 'Draft ready for '.$school->school_name.'. Here\'s what the draft hooks into from your profile: '.$hooksJoined.'. Full draft attached below.',
                'action_url' => '/essay-suite',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable $e) {
            return json_encode([
                'ok' => false,
                'error' => 'Draft generation failed: '.$e->getMessage(),
                'retryable' => true,
                'suggestion' => 'Try again in a moment.',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $runner
     * @return array<string, mixed>
     */
    private function runWhySchoolWithRetry(callable $runner): array
    {
        try {
            return $runner();
        } catch (\Throwable) {
            usleep(2_000_000);

            return $runner();
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'college_list_school_id' => $schema->string()
                ->max(36)
                ->description('ULID of the school from the student\'s college list. Get from GetStudentCollegeList first.')
                ->required(),
        ];
    }
}
