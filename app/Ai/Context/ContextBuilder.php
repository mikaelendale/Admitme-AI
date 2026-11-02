<?php

namespace App\Ai\Context;

use App\Domain\Approvals\ProposalStatus;
use App\Domain\Browser\BrowserAgentService;
use App\Domain\Memory\MemoryReader;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentTask;
use App\Models\ConversationSummary;
use App\Models\ExtensionToken;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Assembles a mentor run's instructions: shared rules, one role, the mode's permissions, a compact
 * student brief at a recorded profile revision, and (when older turns are not replayed) an attributed
 * conversation summary. Saved facts always outrank derived notes and summaries.
 */
final class ContextBuilder
{
    public function __construct(
        private readonly InstructionLoader $instructions,
        private readonly MemoryReader $memory,
        private readonly BrowserAgentService $browser,
    ) {}

    public function forMentor(User $student, ChatMode $mode, ?string $conversationId = null, ?HistoryWindow $window = null): MentorContext
    {
        $window ??= HistoryWindow::empty();
        $brief = $this->memory->brief($student);
        $revision = (int) $brief['profile_revision'];

        [$summary, $summaryOutdated, $summaryText] = $this->summaryPart($student, $conversationId, $window, $revision);

        $parts = [
            'shared' => $this->instructions->load('AGENTS.md'),
            'role' => $this->instructions->load('agents/mentor.md'),
            'mode' => $this->instructions->load("modes/{$mode->value}.md", ['college_list_rule' => $this->collegeListRule()]),
            'memory' => $this->fit($this->renderBrief($brief), (int) config('admitme.context.memory_tokens', 1800), 'use ReadStudentProfileTool for the rest'),
            'derived' => $this->derivedNotes($student),
            'approvals' => $this->approvalsPart($student),
            'tasks' => $this->tasksPart($student),
            'browser' => $this->browserPart($student, $mode),
            'summary' => $summaryText,
        ];

        $parts = array_filter($parts, fn (string $part) => $part !== '');

        $estimated = array_map(fn (string $part) => self::estimate($part), $parts);
        $estimated['history'] = $window->estimatedTokens;
        $estimated['total'] = array_sum($estimated);

        return new MentorContext(
            instructions: implode("\n\n---\n\n", $parts),
            estimatedTokens: $estimated,
            profileRevision: $revision,
            instructionVersion: $this->instructions->version(),
            summary: $summary,
            summaryOutdated: $summaryOutdated,
        );
    }

    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function renderBrief(array $brief): string
    {
        $lines = [
            "## Student brief (profile revision {$brief['profile_revision']})",
            'These are the student\'s saved records. "Confirmed" means the student entered or approved the value.',
        ];

        $missing = [];
        $unknown = [];

        foreach ($brief['sections'] as $section => $projection) {
            $missing = [...$missing, ...$projection['missing']];
            $unknown = [...$unknown, ...($projection['unknown'] ?? [])];

            if ($projection['fields'] === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = '### '.Str::headline($section);

            foreach ($projection['fields'] as $field) {
                $lines[] = "- {$field['label']}: {$this->formatValue($field['value'])} ({$field['provenance']})";
            }
        }

        $lines[] = '';
        $lines[] = '### Not on file';
        $lines[] = $missing === [] ? '- Nothing tracked is missing.' : '- '.implode('; ', $missing);

        if ($unknown !== []) {
            $lines[] = '### The student said they don\'t know / it doesn\'t apply (don\'t ask again unless it matters)';
            $lines[] = '- '.implode('; ', $unknown);
        }

        if (($brief['pending_document_values'] ?? 0) > 0) {
            $lines[] = '### Documents';
            $lines[] = "- {$brief['pending_document_values']} value(s) read from the student's documents are waiting for their review on the Documents page. They are NOT facts yet — never treat them as confirmed.";
        }

        $lines[] = '';
        $lines[] = '### College list and applications';
        $lines[] = '- College list: '.($brief['college_list'] === [] ? 'empty' : $this->counts($brief['college_list']));
        $lines[] = '- Applications: '.($brief['applications'] === [] ? 'none started' : $this->counts($brief['applications']));

        return implode("\n", $lines);
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            $scalars = array_filter($value, fn (mixed $item) => is_scalar($item));

            if (count($scalars) === count($value)) {
                return implode(', ', array_map('strval', $value));
            }

            return count($value).' item(s) on file — read the profile section for detail';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return Str::limit(trim((string) $value), 400, '…');
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counts(array $counts): string
    {
        return implode(', ', array_map(fn (string $status, int $count) => "{$count} ".str_replace('_', ' ', $status), array_keys($counts), $counts));
    }

    /**
     * AI-generated profile insights from the older context builder. Derived, so labelled and flagged when
     * the student's facts changed after they were generated.
     */
    private function derivedNotes(User $student): string
    {
        $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->first(['ai_context', 'last_ai_context_at']);
        $context = is_array($profile?->ai_context) ? $profile->ai_context : [];

        if ($context === []) {
            return '';
        }

        $generatedAt = $profile->last_ai_context_at;
        $latestFactAt = StudentFact::query()->where('user_id', $student->getKey())->max('created_at');
        $outdated = $generatedAt === null || ($latestFactAt !== null && $generatedAt->lt($latestFactAt));

        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        return implode("\n", [
            '## Derived AI notes (not authoritative)',
            'Generated by an earlier AI pass'.($generatedAt ? ' on '.$generatedAt->toDateString() : '').'.'
                .($outdated ? ' The student\'s facts changed after this was generated — where they disagree, the student brief wins.' : ' Where they disagree with the student brief, the brief wins.'),
            $this->fit($json, 600, 'notes truncated'),
        ]);
    }

    /**
     * @return array{0: ?ConversationSummary, 1: bool, 2: string}
     */
    private function summaryPart(User $student, ?string $conversationId, HistoryWindow $window, int $revision): array
    {
        if ($conversationId === null || $window->excludedRows() === 0) {
            return [null, false, ''];
        }

        $summary = ConversationSummary::query()
            ->where('user_id', $student->getKey())
            ->where('conversation_id', $conversationId)
            ->latest('id')
            ->first();

        if ($summary === null) {
            return [null, false, implode("\n", [
                '## Earlier in this conversation',
                "{$window->excludedRows()} older turn(s) are not shown and have not been summarised yet. If the student refers to something you cannot see, ask them to remind you.",
            ])];
        }

        $outdated = $summary->profile_revision < $revision;

        $lines = [
            '## Earlier in this conversation (derived summary of '.$summary->message_count.' older turn(s))',
            $outdated
                ? "Written at profile revision {$summary->profile_revision}; the profile is now at revision {$revision}. Where this summary disagrees with the student brief, the brief is correct."
                : 'Derived from the stored messages. The student brief outranks it.',
        ];

        foreach ($summary->items as $item) {
            if (is_array($item) && is_string($item['text'] ?? null)) {
                $lines[] = '- '.$item['text'];
            }
        }

        return [$summary, $outdated, $this->fit(implode("\n", $lines), 800, 'summary truncated')];
    }

    /**
     * What is waiting for the student's approval and what they recently decided, so the mentor resumes
     * with accurate state instead of assuming proposals were applied.
     */
    private function approvalsPart(User $student): string
    {
        $pending = Proposal::query()
            ->where('user_id', $student->getKey())
            ->where('status', ProposalStatus::Pending)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->limit(5)
            ->get();

        $recent = Proposal::query()
            ->where('user_id', $student->getKey())
            ->where('status', '!=', ProposalStatus::Pending)
            ->where('updated_at', '>=', now()->subDays(14))
            ->with('decision')
            ->latest('updated_at')
            ->limit(5)
            ->get();

        if ($pending->isEmpty() && $recent->isEmpty()) {
            return '';
        }

        $lines = ['## Approvals'];

        if ($pending->isNotEmpty()) {
            $lines[] = 'Waiting for the student (nothing has changed yet):';

            foreach ($pending as $proposal) {
                $lines[] = "- {$proposal->title}: ".implode('; ', array_column($proposal->items, 'label'));
            }
        }

        if ($recent->isNotEmpty()) {
            $lines[] = 'Recently decided:';

            foreach ($recent as $proposal) {
                // Name what was applied the way the student saw it on the card, not by internal item keys...
                $labels = array_column($proposal->items, 'label', 'key');
                $applied = [];

                foreach ($proposal->decision?->receipt ?? [] as $key => $outcome) {
                    if ($outcome === 'applied' || $outcome === 'queued') {
                        $applied[] = ($labels[$key] ?? (string) $key).($outcome === 'queued' ? ' (in progress)' : '');
                    }
                }

                $lines[] = match ($proposal->status) {
                    ProposalStatus::Applied => "- Approved: {$proposal->title} — applied: ".($applied === [] ? 'nothing new' : implode('; ', $applied)),
                    ProposalStatus::Rejected => "- Rejected by the student: {$proposal->title}",
                    ProposalStatus::Stale => "- Not applied (the data changed before approval): {$proposal->title}",
                    ProposalStatus::Expired => "- Expired without a decision: {$proposal->title}",
                    default => "- {$proposal->status->value}: {$proposal->title}",
                };
            }
        }

        return $this->fit(implode("\n", $lines), 400, 'approvals truncated');
    }

    /**
     * Recent background plans and their steps, so the mentor hears when workers finish, fail, block or wait
     * for approval — even in a later turn or after the student switched to Ask mode.
     */
    private function tasksPart(User $student): string
    {
        $goals = AgentTask::query()
            ->where('user_id', $student->getKey())
            ->whereNull('parent_id')
            ->where(fn ($q) => $q->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Failed->value, TaskStatus::Cancelled->value])
                ->orWhere('updated_at', '>=', now()->subDays(2)))
            ->latest('created_at')
            ->limit(3)
            ->get();

        if ($goals->isEmpty()) {
            return '';
        }

        $lines = ['## Tasks (background plans; the student sees them in the task panel)'];

        foreach ($goals as $goal) {
            $lines[] = "- {$goal->title}: {$goal->status->label()}".($goal->status_reason ? " — {$goal->status_reason}" : '');

            foreach ($goal->steps()->get() as $step) {
                $detail = $step->result['summary'] ?? $step->status_reason;
                $lines[] = "  - {$step->title}: {$step->status->label()}".($detail ? ' — '.Str::limit((string) $detail, 180) : '');
            }
        }

        return $this->fit(implode("\n", $lines), 450, 'tasks truncated — use ReadTasksTool');
    }

    /**
     * One line on the student's browser: the tab they gave the mentor (and where it is), or — in Agent mode, when the
     * extension is linked — how they turn control on. The address is host and path only: it comes from a web page.
     */
    private function browserPart(User $student, ChatMode $mode): string
    {
        $control = $this->browser->controlFor($student);

        if ($control !== null) {
            $where = BrowserAgentService::describeUrl($control->current_url);
            $on = $where !== null ? ", now on {$where}" : ' (not on a web page right now)';

            return $mode === ChatMode::Agent
                ? "Browser: the student gave you control of a tab in their browser{$on}. Use the Browser tools to open, read and fill pages."
                : "Browser: the student gave you control of a tab in their browser{$on}, but the Browser tools work only in Agent mode: if they want you to work in it, ask them to switch to Agent.";
        }

        $linked = $mode === ChatMode::Agent && ExtensionToken::query()
            ->where('user_id', $student->getKey())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        return $linked
            ? 'Browser: the AdmitMe extension is linked, but browser control is off. To work in their browser (e.g. fill a portal), ask them to open the extension\'s side panel and press "Let the mentor use this browser".'
            : '';
    }

    /**
     * Rule text for the college-list tools, which depends on whether the council is enabled.
     */
    private function collegeListRule(): string
    {
        $council = (bool) config('college_list_council.enabled');

        if (! $council) {
            return '- Build/rebuild a list: RunCollegeListAgentTool with their message and intent "auto" (or generate/refine/add/remove); summarize its `chat_response`.';
        }

        $rule = '- Build/rebuild/rebalance a list: StartCollegeListCouncilJobTool with their message and intent "auto" (or generate/refine/add/remove). It queues a council run with a live card; clarifications appear in that card.'
            ."\n".'- Why a school was excluded: GetCollegeListRunArtifactsTool with the run id; answer only from that stored data.';

        if ((bool) config('college_list_council.sync_fallback')) {
            $rule .= "\n".'- If they need an immediate list instead of the background council, RunCollegeListAgentTool is available; prefer the council.';
        }

        return $rule;
    }

    private function fit(string $text, int $tokenBudget, string $note): string
    {
        $limit = $tokenBudget * 4;

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit))."\n… ({$note})";
    }
}
