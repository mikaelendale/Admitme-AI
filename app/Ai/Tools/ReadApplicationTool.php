<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Domain\Applications\ApplicationWorkspace;
use App\Models\Application;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The application workspace exactly as the student sees it on screen (same read model).
 */
class ReadApplicationTool implements ReadOnlyTool, Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Read the student\'s applications as shown in their workspace: status, deadline (with whether it comes from a source or an unverified estimate), progress, next actions, blockers and the checklist (each step with its owner, status, due date and source). Pass an application id for the full workspace, or null for a summary of every application. A third-party step (recommendation, school report, official scores) is only complete when it is "received"; "requested" means the student asked and is still waiting. An application is only "submitted" when the student recorded evidence. AdmitMe does not check portals for received items. To work in the portal itself, use the Browser tools (Agent mode, once the student has given you control of a tab): fill it, and click a final submit only after the student says yes in the chat and presses Allow in their side panel.';
    }

    public function handle(Request $request): Stringable|string
    {
        $workspace = app(ApplicationWorkspace::class);
        $id = $request['application_id'] ?? null;

        if (is_string($id) && $id !== '') {
            $application = Application::query()->where('user_id', $this->user->getKey())->whereKey($id)->first();

            if ($application === null) {
                return json_encode(['error' => 'No application with that id exists for this student.']) ?: '{}';
            }

            $full = $workspace->for($application);

            return json_encode([
                'application' => [
                    ...$full['application'],
                    'college' => $full['college']['name'],
                    'portal_url' => $full['portal']['url'],
                ],
                'deadline' => $full['deadline'],
                'progress' => $full['progress'],
                'next_actions' => array_column($full['nextActions'], 'text'),
                'blockers' => array_column($full['blockers'], 'text'),
                'checklist' => array_map(fn (array $item) => [
                    'title' => $item['title'],
                    'owner' => $item['owner'] === 'third_party' ? ($item['ownerLabel'] ?? 'someone else') : 'student',
                    'status' => $item['statusLabel'],
                    'complete' => $item['complete'],
                    'how_we_know' => $item['completionBasis'],
                    'due' => $item['dueDate'],
                    'overdue' => $item['overdue'],
                    'note' => $item['statusNote'],
                    'source' => $item['source']['label'] ?? null,
                    'source_missing' => $item['sourceMissing'],
                ], $full['checklist']),
                'requirements_unresolved' => $full['requirements']['unresolved'],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }

        return json_encode([
            'applications' => Application::query()
                ->where('user_id', $this->user->getKey())
                ->orderBy('created_at')
                ->get()
                ->map(fn (Application $application) => $workspace->summary($application))
                ->values()
                ->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'application_id' => $schema->string()
                ->description('One application id from a previous read, or null to list all applications.')
                ->nullable()
                ->required(),
        ];
    }
}
