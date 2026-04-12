<?php

namespace App\Support;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MentorChatConversation
{
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

    public static function ownedByUser(string $conversationId, User $user): bool
    {
        return DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->exists();
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
        $records = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('agent', AdmitMeGeneralAgent::class)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($records as $record) {
            if ($record->role === 'user') {
                $out[] = [
                    'id' => (string) $record->id,
                    'role' => 'user',
                    'content' => (string) $record->content,
                ];

                continue;
            }

            if ($record->role !== 'assistant') {
                continue;
            }

            $content = trim((string) $record->content);
            $toolCalls = json_decode($record->tool_calls, true);
            $activity = [];

            if (is_array($toolCalls) && $toolCalls !== []) {
                foreach ($toolCalls as $call) {
                    $name = $call['name'] ?? null;
                    if ($name && is_string($name)) {
                        $label = match ($name) {
                            'GetStudentAdmissionContextTool' => 'Reading your profile…',
                            'GetStudentCollegeList' => 'Loading your college list…',
                            'StartEssayRunTool' => 'Starting essay generation…',
                            'StartGapFixTool' => 'Running gap analysis…',
                            'StartEssayRoastTool' => 'Roasting your essay…',
                            'StartWhyThisSchoolTool' => 'Writing Why This School draft…',
                            'StartCollegeListCouncilJobTool' => 'Starting college list council…',
                            'RunCollegeListAgentTool' => 'Building your college list…',
                            'UpsertStudentCollegeList' => 'Updating your list…',
                            'GetCollegeListRunArtifactsTool' => 'Loading council results…',
                            default => "Thinking ({$name})…",
                        };
                        $activity[] = [
                            'id' => uniqid('act_', true),
                            'text' => $label,
                        ];
                    }
                }
            }

            if ($content === '') {
                if (is_array($toolCalls) && $toolCalls !== []) {
                    $names = collect($toolCalls)
                        ->pluck('name')
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(', ');
                    $content = $names !== ''
                        ? "[Used tools: {$names}]"
                        : '[Assistant used tools]';
                } else {
                    $content = '';
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

            $toolResults = json_decode($record->tool_results, true);
            if (is_array($toolResults) && $toolResults !== []) {
                $hints = self::jobHintsFromToolResults($toolResults);
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

        return $out;
    }

    /**
     * @return list<array{id: string, title: string, updated_at: string|null}>
     */
    public static function recentConversationsForUser(int $userId, int $limit = 50): array
    {
        $rows = DB::table('agent_conversations')
            ->where('user_id', $userId)
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
