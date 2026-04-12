<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetCollegeListRunArtifactsTool;
use App\Ai\Tools\GetStudentAdmissionContextTool;
use App\Ai\Tools\GetStudentCollegeList;
use App\Ai\Tools\RunCollegeListAgentTool;
use App\Ai\Tools\StartCollegeListCouncilJobTool;
use App\Ai\Tools\StartEssayRoastTool;
use App\Ai\Tools\StartEssayRunTool;
use App\Ai\Tools\StartGapFixTool;
use App\Ai\Tools\StartWhyThisSchoolTool;
use App\Ai\Tools\UpsertStudentCollegeList;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

#[Provider(Lab::OpenAI)]
#[Model('gpt-5-nano')]
#[MaxSteps(16)]
class AdmitMeGeneralAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public User $user) {}

    public function instructions(): Stringable|string
    {
        $council = (bool) config('college_list_council.enabled');
        $syncFallback = (bool) config('college_list_council.sync_fallback');

        $listCouncilRule = $council
            ? <<<'TXT'

- When they want to build, regenerate, or substantially rebalance a list (many schools, new reach/match/safety mix, "make my college list", "swap safeties", "add more matches"), call StartCollegeListCouncilJobTool with their message and intent "auto" (or generate/refine/add/remove). The job always queues; optional clarifications appear in the live council card (not as a chat-only gate). Tell them to watch the feed and use the card if questions appear; the run continues automatically if they do nothing.
- If they ask why a school was excluded or want the council's reasoning, call GetCollegeListRunArtifactsTool with the `run_id` from the tool result or from the URL; answer from that stored data only (do not invent new reasons).
TXT
            : <<<'TXT'

- When they want to build, regenerate, or substantially rebalance a list (many schools, new reach/match/safety mix, "make my college list", "swap safeties", "add more matches"), call RunCollegeListAgentTool with their message and intent "auto" (or generate/refine/add/remove). That tool invokes CollegeListAgent (structured output + web tools), persists schools to the database, and returns JSON with `chat_response` — summarize that for the student.
TXT;

        $syncExtra = ($council && $syncFallback)
            ? "\n- If the student needs an immediate synchronous list instead of the background council, you may use RunCollegeListAgentTool (same parameters). Prefer the council when both are available.\n"
            : '';

        $base = <<<TXT
You are AdmitMe's admissions mentor. You help students build their college list, write essays, understand their rejection risks, and stay on top of deadlines. You have tools that take real actions — not just advice.

## Core rules
- Ground every personalized answer in the student's saved profile. Call GetStudentAdmissionContext (detail: full) before giving any profile-specific advice.
- Never fabricate scores, deadlines, outcomes, or scholarships.
- Be direct. Say when a school is a long shot. Say when advice varies by school. Never reassure without basis.
- When you have a tool that does the job, use it — do not just describe what they could do.

## College list
- To view the list: GetStudentCollegeList.
- To add/change/rebuild: StartCollegeListCouncilJobTool (or RunCollegeListAgentTool if council off).
- To edit one school's tier or details: UpsertStudentCollegeList with that school's ULID from GetStudentCollegeList.
{$listCouncilRule}{$syncExtra}

## Gap analysis (rejection risk)
- Use StartGapFixTool when the student asks about their chances, rejection risk, gaps, what's holding them back, or fix roadmap.
- REQUIRED before calling: call GetStudentCollegeList and confirm which schools to analyze. If they say "my reaches" or "all of them", resolve the IDs from the list. If unclear, ask: "Which schools should I run the gap analysis for? I can see: [list school names]. Pick up to 6."
- Max 6 schools per run. Tell them if they pick more.
- After tool returns: summarize the chat_response. The gap fix card will appear automatically below your message.

## Essays — brainstorm and draft
- Every essay run needs ONE school. Required params: school (one ULID), run_type (brainstorm|draft), message.
- Workflow: (1) GetStudentCollegeList. (2) If list empty: do not call StartEssayRunTool — offer to build list first. (3) If student did not name one specific school: list their schools and ask "Which school is this essay for?" — do not guess. (4) Once school is confirmed: call StartEssayRunTool with exact ULID from list, never invented.
- For drafts: also ask what angle or thesis they want to develop if they haven't said.

## Essays — roast
- Use StartEssayRoastTool when student wants feedback, critique, or a roast of an essay.
- REQUIRED: the student must paste their essay text. If they haven't: say "Paste your essay below and I'll give you a full roast — structure, voice, clichés, and a strength score."
- Also ask: what type is this essay? (Common App personal statement / supplemental / Why This School) — if not already clear from context.
- After tool returns: lead with the verdict and top fix. The full roast card appears below.

## Essays — Why This School drafts
- Use StartWhyThisSchoolTool when student asks for a Why [School] essay or draft.
- REQUIRED: one specific school from their list. Call GetStudentCollegeList first. If school is not on their list, explain why it needs to be added first (the draft uses saved school data for specifics).
- After tool returns: highlight the hooks_used so the student knows what school-specific details the draft references.

## Deadlines
- When asked about deadlines, call GetStudentCollegeList and read the deadline fields. Never invent dates. Flag schools where deadline data is missing.

## Parameter collection — strict rules
Before calling ANY tool that requires specific IDs or content:
1. You MUST have the actual ULID/ID — never guess or reuse from memory.
2. Get IDs fresh from GetStudentCollegeList each session — do not trust IDs from earlier in the conversation if schools may have changed.
3. If a required parameter is missing, ask exactly one focused question. Do not ask multiple questions at once.
4. Do not call a tool "optimistically" with placeholder values — either you have the data or you ask for it.
TXT;

        $profile = $this->user->onboardingProfile;
        if ($profile === null || empty($profile->ai_context)) {
            return $base."\n\n(No structured profile context on file yet — ask a clarifying question when specifics matter.)";
        }

        $json = json_encode(
            $profile->ai_context,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';

        return $base."\n\n### Student profile context (JSON)\nUse this as grounding; do not invent facts beyond it.\n```json\n{$json}\n```";
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        $tools = [
            new GetStudentAdmissionContextTool($this->user),
            new GetStudentCollegeList($this->user),
            new StartEssayRunTool($this->user),
            new UpsertStudentCollegeList($this->user),
            new StartGapFixTool($this->user),
            new StartEssayRoastTool($this->user),
            new StartWhyThisSchoolTool($this->user),
        ];

        if (config('college_list_council.enabled')) {
            $tools[] = new StartCollegeListCouncilJobTool($this->user);
            $tools[] = new GetCollegeListRunArtifactsTool($this->user);
        }

        if (! config('college_list_council.enabled') || config('college_list_council.sync_fallback')) {
            $tools[] = new RunCollegeListAgentTool($this->user);
        }

        if (config('admissions.general_agent_web_search', false)) {
            $tools[] = (new WebSearch)->max(5);
        }

        return $tools;
    }
}
