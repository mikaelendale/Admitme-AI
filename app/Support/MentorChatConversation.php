<?php

namespace App\Support;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Agents\Subagents\SubagentActivity;
use App\Ai\Context\MessageAttachments;
use App\Domain\Approvals\ProposalPresenter;
use App\Domain\Approvals\ProposalService;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Enums\MessageStatus;

class MentorChatConversation
{
    /**
     * Tool names of the specialists the mentor delegates to (App\Ai\Agents\Subagents).
     */
    public const SUBAGENTS = ['researcher', 'college_scout', 'application_planner', 'writing_coach'];

    /**
     * @param  list<array<string, mixed>>  $toolResults
     * @return array{
     *   council_feed: ?array{run_id: string, status: string},
     *   essay_job: ?array{run_id: string, run_type: string, status: string},
     *   gap_fix_data: ?array,
     *   essay_roast_data: ?array,
     *   why_school_data: ?array
     * }
     */
    public static function jobHintsFromToolResults(array $toolResults): array
    {
        $councilFeed = null;
        $essayJob = null;
        $gapFixData = null;
        $essayRoastData = null;
        $whySchoolData = null;

        foreach ($toolResults as $row) {
            if (! is_array($row)) {
                continue;
            }

            $payload = $row['result'] ?? null;
            if (! is_string($payload) || $payload === '') {
                continue;
            }

            $json = json_decode($payload, true);
            if (! is_array($json) || ($json['ok'] ?? false) !== true) {
                continue;
            }

            if (($json['council_started'] ?? false) === true && isset($json['run_id']) && is_string($json['run_id']) && $json['run_id'] !== '') {
                $councilFeed = [
                    'run_id' => $json['run_id'],
                    'status' => self::normalizeCouncilFeedStatus((string) ($json['status'] ?? 'running')),
                ];
            }

            if (($json['essay_job_started'] ?? false) === true && isset($json['run_id']) && is_string($json['run_id']) && $json['run_id'] !== '') {
                $runType = (($json['run_type'] ?? '') === 'draft') ? 'draft' : 'brainstorm';
                $essayJob = [
                    'run_id' => $json['run_id'],
                    'run_type' => $runType,
                    'status' => self::normalizeEssayJobStatus((string) ($json['status'] ?? 'running')),
                ];
            }

            if (($json['gap_fix_started'] ?? false) === true) {
                $gapFixData = [
                    'school_names' => $json['school_names'] ?? [],
                    'school_count' => $json['school_count'] ?? 0,
                    'action_url' => $json['action_url'] ?? '',
                    'analysis_id' => $json['analysis_id'] ?? '',
                ];
            }

            if (($json['roast_ready'] ?? false) === true) {
                $essayRoastData = [
                    'overall_verdict' => $json['overall_verdict'] ?? '',
                    'scores' => $json['scores'] ?? [],
                    'cliche_count' => $json['cliche_count'] ?? 0,
                    'top_priority_fix' => $json['top_priority_fix'] ?? '',
                    'action_url' => $json['action_url'] ?? '',
                ];
            }

            if (($json['why_school_ready'] ?? false) === true) {
                $whySchoolData = [
                    'school_name' => $json['school_name'] ?? '',
                    'draft_preview' => $json['draft_preview'] ?? '',
                    'hooks_used' => $json['hooks_used'] ?? [],
                    'action_url' => $json['action_url'] ?? '',
                ];
            }
        }

        return [
            'council_feed' => $councilFeed,
            'essay_job' => $essayJob,
            'gap_fix_data' => $gapFixData,
            'essay_roast_data' => $essayRoastData,
            'why_school_data' => $whySchoolData,
        ];
    }

    public static function normalizeCouncilFeedStatus(string $raw): string
    {
        $s = strtolower(trim($raw));

        return match ($s) {
            'completed', 'failed' => $s,
            'awaiting_preflight' => 'awaiting_preflight',
            default => 'running',
        };
    }

    public static function normalizeEssayJobStatus(string $raw): string
    {
        $s = strtolower(trim($raw));

        return match ($s) {
            'completed', 'failed' => $s,
            default => 'running',
        };
    }

    /**
     * A short note on what a browser tool worked on, as the chat shows it while the reply streams
     * (toolDetail in mentor-tools.tsx): the page it opened, how many fields it filled, the key it pressed.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function browserToolDetail(string $name, array $arguments): ?string
    {
        if ($name === 'BrowserOpenTool' && is_string($arguments['url'] ?? null)) {
            $parts = parse_url($arguments['url']);
            $host = $parts['host'] ?? null;

            if (! is_string($host)) {
                return null;
            }

            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $path = ($parts['path'] ?? '/') === '/' ? '' : $parts['path'];

            return mb_substr($host.$port.$path, 0, 80);
        }

        if ($name === 'BrowserFillTool' && is_array($arguments['fields'] ?? null)) {
            $count = count($arguments['fields']);

            return $count === 1 ? '1 field' : "{$count} fields";
        }

        if ($name === 'BrowserPressKeyTool' && is_string($arguments['key'] ?? null)) {
            return mb_substr($arguments['key'], 0, 20);
        }

        return null;
    }

    public static function ownedByUser(string $conversationId, User $user): bool
    {
        return ConversationOwnership::userOwnsConversation((int) $user->id, $conversationId);
    }

    /**
     * A stored conversation as the chat panel shows it, or null when it is not stored (yet) or not the student's.
     * A new conversation is stored when its first reply ends.
     *
     * @return array{conversation_id: string, title: string|null, updated_at: string|null, messages: list<array<string, mixed>>}|null
     */
    public static function forDisplay(string $conversationId, int|string $userId): ?array
    {
        $row = ConversationOwnership::whereUser(DB::table('agent_conversations'), $userId)
            ->where('id', $conversationId)
            ->first(['title', 'updated_at']);

        if ($row === null) {
            return null;
        }

        return [
            'conversation_id' => $conversationId,
            'title' => $row->title !== null ? (string) $row->title : null,
            'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
            'messages' => self::messagesForDisplay($conversationId, $userId),
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     role: 'user'|'assistant',
     *     content: string,
     *     council_feed?: array{run_id: string, status: string},
     *     essay_job?: array{run_id: string, run_type: string, status: string},
     *     gap_fix_data?: array,
     *     essay_roast_data?: array,
     *     why_school_data?: array,
     *     activity?: list<array{id: string, text: string}>
     * }>
     */
    public static function messagesForDisplay(string $conversationId, int|string $userId): array
    {
        $records = ConversationOwnership::whereUser(DB::table('agent_conversation_messages'), $userId)
            ->where('conversation_id', $conversationId)
            ->where('agent', AdmitMeGeneralAgent::class)
            // Laravel AI 1.x stores turns that threw; keep showing only replies that actually completed...
            ->where('status', '!=', MessageStatus::Failed->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($records as $record) {
            if ($record->role === 'user') {
                [$text, $attached] = MessageAttachments::parseStored((string) $record->content);
                $out[] = [
                    'id' => (string) $record->id,
                    'role' => 'user',
                    'content' => $text,
                    'attachments' => $attached,
                ];

                continue;
            }

            if ($record->role !== 'assistant') {
                continue;
            }

            $content = trim((string) $record->content);
            $toolCalls = self::storedToolCalls($record->steps);
            $activity = [];

            if ($toolCalls !== []) {
                foreach ($toolCalls as $call) {
                    $name = $call['name'] ?? null;
                    if ($name && is_string($name)) {
                        $label = match ($name) {
                            'ReadStudentProfileTool' => 'Reading your profile',
                            'ReadCollegeListTool' => 'Loading your college list',
                            'ReadApplicationTool' => 'Checking your applications',
                            'GetActiveJobsTool' => 'Checking background work',
                            'GetFeatureQuotaTool' => 'Checking your plan limits',
                            'ProposeProfileChangesTool' => 'Proposing profile changes',
                            'ProposeCollegeListAdditionsTool' => 'Proposing colleges',
                            'GetStudentAdmissionContextTool' => 'Reading your profile',
                            'GetStudentCollegeList' => 'Loading your college list',
                            'StartEssayRunTool' => 'Starting essay',
                            'StartGapFixTool' => 'Analyzing gaps',
                            'StartEssayRoastTool' => 'Roasting your essay',
                            'StartWhyThisSchoolTool' => 'Drafting Why This School',
                            'StartCollegeListCouncilJobTool' => 'Building your list',
                            'RunCollegeListAgentTool' => 'Building your list',
                            'UpsertStudentCollegeList' => 'Updating your list',
                            'GetCollegeListRunArtifactsTool' => 'Checking results',
                            'ReadCollegeRequirementsTool' => 'Checking college requirements',
                            'SearchMyDocumentsTool' => 'Searching your documents',
                            'PlanTasksTool' => 'Starting background tasks',
                            'ReadTasksTool' => 'Checking your tasks',
                            'RequestExportTool' => 'Preparing your export',
                            'PrepareBrowserFillTool' => 'Preparing a form fill',
                            'BrowserOpenTool' => 'Opened a page in your browser',
                            'BrowserReadTool' => 'Read the page',
                            'BrowserFillTool' => 'Filled in fields',
                            'BrowserClickTool' => 'Clicked on the page',
                            'BrowserPressKeyTool' => 'Pressed a key',
                            'WebSearch', 'web_search' => 'Searched the web',
                            default => 'Working on it',
                        };
                        $icon = match ($name) {
                            'ReadStudentProfileTool', 'ProposeProfileChangesTool' => 'profile',
                            'ReadCollegeListTool', 'ReadApplicationTool', 'ProposeCollegeListAdditionsTool' => 'list',
                            'GetStudentAdmissionContextTool' => 'profile',
                            'GetStudentCollegeList' => 'list',
                            'StartEssayRunTool' => 'essay',
                            'StartGapFixTool' => 'gap',
                            'StartEssayRoastTool' => 'roast',
                            'StartWhyThisSchoolTool' => 'essay',
                            'StartCollegeListCouncilJobTool', 'RunCollegeListAgentTool' => 'council',
                            'UpsertStudentCollegeList', 'GetCollegeListRunArtifactsTool' => 'list',
                            'WebSearch', 'web_search' => 'search',
                            default => 'default',
                        };
                        $activity[] = [
                            'id' => uniqid('act_', true),
                            'text' => $label,
                            'icon' => $icon,
                            'status' => 'done',
                        ];
                    }
                }
            }

            if ($content === '' && $activity === []) {
                continue;
            }

            $row = [
                'id' => (string) $record->id,
                'role' => 'assistant',
                'content' => $content,
            ];

            if ($activity !== []) {
                $row['activity'] = $activity;
            }

            $parts = self::partsFromSteps($record->steps);
            if ($parts !== []) {
                $row['parts'] = $parts;
            }

            // Each stored tool call carries its own `result`, so the calls double as the tool results...
            if ($toolCalls !== []) {
                $proposalIds = self::proposalIdsFromToolResults($toolCalls);
                if ($proposalIds !== []) {
                    $row['proposal_ids'] = $proposalIds;
                }

                $goalIds = self::taskGoalIdsFromToolResults($toolCalls);
                if ($goalIds !== []) {
                    $row['task_goal_ids'] = $goalIds;
                }

                $hints = self::jobHintsFromToolResults($toolCalls);
                if ($hints['council_feed'] !== null) {
                    $row['council_feed'] = $hints['council_feed'];
                }
                if ($hints['essay_job'] !== null) {
                    $row['essay_job'] = $hints['essay_job'];
                }
                if ($hints['gap_fix_data'] !== null) {
                    $row['gap_fix_data'] = $hints['gap_fix_data'];
                }
                if ($hints['essay_roast_data'] !== null) {
                    $row['essay_roast_data'] = $hints['essay_roast_data'];
                }
                if ($hints['why_school_data'] !== null) {
                    $row['why_school_data'] = $hints['why_school_data'];
                }
            }

            $out[] = $row;
        }

        return self::withProposalData($out, $userId);
    }

    /**
     * A turn in the order it happened: each step's text, then the tools that step called, with the card a tool
     * produced (an approval, a plan, a background job) right after it — so the chat shows tools between
     * paragraphs, where they ran.
     *
     * @return list<array<string, mixed>>
     */
    public static function partsFromSteps(?string $steps): array
    {
        $decoded = json_decode($steps ?? '', true);

        if (! is_array($decoded)) {
            return [];
        }

        $parts = [];
        $cards = [];

        foreach ($decoded as $step) {
            if (! is_array($step)) {
                continue;
            }

            // Web searches run while the step's text is written, so they read best before it.
            foreach (self::webSearches((array) ($step['provider_tool_calls'] ?? [])) as $search) {
                $parts[] = $search;
            }

            $text = trim((string) ($step['content'] ?? ''));

            if ($text !== '') {
                $parts[] = ['type' => 'text', 'text' => $text];
            }

            foreach ((array) ($step['tool_calls'] ?? []) as $call) {
                if (! is_array($call) || ! is_string($call['name'] ?? null)) {
                    continue;
                }

                $result = is_string($call['result'] ?? null) ? json_decode($call['result'], true) : null;
                $failed = (bool) ($call['failed'] ?? false) || (bool) ($call['denied'] ?? false);

                // A specialist's task and what it wrote come back with it, so its card reopens as it finished.
                if (in_array($call['name'], self::SUBAGENTS, true)) {
                    $task = $call['arguments']['task'] ?? null;
                    [$report, $activity] = SubagentActivity::split((string) ($call['result'] ?? ''));
                    $parts[] = [
                        'type' => 'tool',
                        'id' => (string) ($call['id'] ?? ''),
                        'name' => $call['name'],
                        'status' => $failed || str_starts_with($report, 'Agent failed:') ? 'failed' : 'done',
                        'detail' => is_string($task) ? mb_substr($task, 0, 400) : null,
                        'output' => mb_substr($report, 0, 12000),
                        'activity' => $activity,
                        'sources' => [],
                    ];

                    continue;
                }

                $parts[] = [
                    'type' => 'tool',
                    'id' => (string) ($call['id'] ?? ''),
                    'name' => $call['name'],
                    'status' => $failed ? 'failed' : 'done',
                    'detail' => self::browserToolDetail($call['name'], (array) ($call['arguments'] ?? [])),
                    'sources' => is_array($result) && ! $failed ? self::sourcesFromResult($result) : [],
                ];

                if (! is_array($result)) {
                    continue;
                }

                if (($result['status'] ?? null) === 'pending_approval' && is_string($result['proposal_id'] ?? null)) {
                    $parts[] = ['type' => 'proposal', 'id' => $result['proposal_id']];
                }

                if (in_array($result['status'] ?? null, ['tasks_started', 'task_updated'], true) && is_string($result['goal_task_id'] ?? null)) {
                    $parts[] = ['type' => 'task', 'id' => $result['goal_task_id']];
                }

                $card = match (true) {
                    ($result['ok'] ?? false) !== true => null,
                    ($result['council_started'] ?? false) === true => 'council',
                    ($result['essay_job_started'] ?? false) === true => 'essay',
                    ($result['gap_fix_started'] ?? false) === true => 'gapFix',
                    ($result['roast_ready'] ?? false) === true => 'roast',
                    ($result['why_school_ready'] ?? false) === true => 'whySchool',
                    default => null,
                };

                // One card of each kind per turn, as the message holds one of each.
                if ($card !== null && ! in_array($card, $cards, true)) {
                    $cards[] = $card;
                    $parts[] = ['type' => 'card', 'card' => $card];
                }
            }
        }

        return $parts;
    }

    /**
     * Web searches the provider ran in a step (OpenAI web_search_call, Anthropic server_tool_use), with the
     * query and, when the provider returned them, the titles of what it found.
     *
     * @param  array<int, mixed>  $calls
     * @return list<array<string, mixed>>
     */
    protected static function webSearches(array $calls): array
    {
        $searches = [];

        foreach ($calls as $call) {
            if (! is_array($call)) {
                continue;
            }

            $type = (string) ($call['type'] ?? '');
            $data = is_array($call['data'] ?? null) ? $call['data'] : [];
            $id = (string) ($call['id'] ?? '');

            if ($type === 'web_search_call' || ($type === 'server_tool_use' && ($data['name'] ?? null) === 'web_search')) {
                $query = $data['action']['query'] ?? $data['input']['query'] ?? null;
                $key = $id !== '' ? $id : 'web_'.count($searches);
                $searches[$key] = [
                    'type' => 'tool',
                    'id' => $key,
                    'name' => 'web_search',
                    'status' => 'done',
                    'detail' => is_string($query) ? mb_substr($query, 0, 120) : null,
                    'sources' => [],
                ];

                continue;
            }

            $toolUseId = (string) ($data['tool_use_id'] ?? '');

            if ($type === 'web_search_tool_result' && isset($searches[$toolUseId])) {
                $searches[$toolUseId]['sources'] = self::sourcesFromResult(['sources' => is_array($data['content'] ?? null) ? $data['content'] : []]);
            }
        }

        return array_values($searches);
    }

    /**
     * A few short references from a tool result (document pages, source titles or hosts), shown with the tool.
     * Mirrors sourcesFromToolOutput() in the chat client.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public static function sourcesFromResult(array $result): array
    {
        $found = [];
        $add = function (mixed $label) use (&$found): void {
            $label = is_string($label) ? mb_substr(trim($label), 0, 64) : '';

            if ($label !== '' && ! in_array($label, $found, true)) {
                $found[] = $label;
            }
        };
        $host = function (mixed $url): ?string {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            return is_string($host) && $host !== '' ? (string) preg_replace('/^www\./', '', $host) : null;
        };

        foreach ((array) ($result['results'] ?? []) as $hit) {
            if (is_array($hit)) {
                $add(is_string($hit['document'] ?? null) ? $hit['document'].(is_int($hit['page'] ?? null) ? ' · p.'.$hit['page'] : '') : ($hit['title'] ?? $host($hit['url'] ?? null)));
            }
        }

        foreach ((array) ($result['requirements'] ?? []) as $requirement) {
            $source = is_array($requirement) ? ($requirement['source'] ?? null) : null;

            if (is_array($source)) {
                $add($source['title'] ?? $host($source['url'] ?? null));
            }
        }

        foreach ((array) ($result['sources'] ?? []) as $source) {
            $add(is_array($source) ? ($source['title'] ?? $host($source['url'] ?? null)) : ($host($source) ?? $source));
        }

        return array_slice($found, 0, 3);
    }

    /**
     * Fill each proposal part with the card's data (one query for the conversation), so reopening a chat shows
     * its approval cards without a request per card. Only the student's own proposals are included.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected static function withProposalData(array $rows, int|string $userId): array
    {
        $ids = [];

        foreach ($rows as $row) {
            foreach ($row['parts'] ?? [] as $part) {
                if ($part['type'] === 'proposal') {
                    $ids[] = $part['id'];
                }
            }
        }

        if ($ids === []) {
            return $rows;
        }

        $service = app(ProposalService::class);
        $presented = Proposal::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->where('user_id', $userId)
            ->with('decision')
            ->get()
            ->mapWithKeys(fn (Proposal $proposal) => [(string) $proposal->getKey() => ProposalPresenter::present($service->refreshExpiry($proposal))]);

        foreach ($rows as $r => $row) {
            foreach ($row['parts'] ?? [] as $p => $part) {
                if ($part['type'] === 'proposal' && $presented->has($part['id'])) {
                    $rows[$r]['parts'][$p]['proposal'] = $presented->get($part['id']);
                }
            }
        }

        return $rows;
    }

    /**
     * Approval proposals created during a turn, so the chat can show their cards again after a reload.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @return list<string>
     */
    public static function proposalIdsFromToolResults(array $toolCalls): array
    {
        $ids = [];

        foreach ($toolCalls as $call) {
            $result = is_string($call['result'] ?? null) ? json_decode($call['result'], true) : null;

            if (is_array($result) && ($result['status'] ?? null) === 'pending_approval' && is_string($result['proposal_id'] ?? null)) {
                $ids[] = $result['proposal_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Background plans started during a turn, so the chat shows their live task cards again after a reload.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @return list<string>
     */
    public static function taskGoalIdsFromToolResults(array $toolCalls): array
    {
        $ids = [];

        foreach ($toolCalls as $call) {
            $result = is_string($call['result'] ?? null) ? json_decode($call['result'], true) : null;

            if (is_array($result) && ($result['status'] ?? null) === 'tasks_started' && is_string($result['goal_task_id'] ?? null)) {
                $ids[] = $result['goal_task_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Flatten the tool calls from every step of a stored assistant turn.
     *
     * @return list<array<string, mixed>>
     */
    protected static function storedToolCalls(?string $steps): array
    {
        $decoded = json_decode($steps ?? '', true);

        if (! is_array($decoded)) {
            return [];
        }

        $calls = [];

        foreach ($decoded as $step) {
            foreach (is_array($step) ? ($step['tool_calls'] ?? []) : [] as $call) {
                if (is_array($call)) {
                    $calls[] = $call;
                }
            }
        }

        return $calls;
    }

    /**
     * @return list<array{id: string, title: string, updated_at: string|null}>
     */
    public static function recentConversationsForUser(int $userId, int $limit = 50): array
    {
        $rows = ConversationOwnership::whereUser(DB::table('agent_conversations'), $userId)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'title', 'updated_at']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
            ];
        }

        return $out;
    }
}
