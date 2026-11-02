<?php

namespace App\Ai\Context;

use App\Ai\Agents\Subagents\ApplicationPlannerSubagent;
use App\Ai\Agents\Subagents\CollegeScoutSubagent;
use App\Ai\Agents\Subagents\MentorSubagent;
use App\Ai\Agents\Subagents\ResearcherSubagent;
use App\Ai\Agents\Subagents\SubagentTool;
use App\Ai\Agents\Subagents\WritingCoachSubagent;
use App\Ai\Tools\BrowserClickTool;
use App\Ai\Tools\BrowserFillTool;
use App\Ai\Tools\BrowserOpenTool;
use App\Ai\Tools\BrowserPressKeyTool;
use App\Ai\Tools\BrowserReadTool;
use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Ai\Tools\GetActiveJobsTool;
use App\Ai\Tools\GetCollegeListRunArtifactsTool;
use App\Ai\Tools\GetFeatureQuotaTool;
use App\Ai\Tools\GetStudentAdmissionContextTool;
use App\Ai\Tools\GetStudentCollegeList;
use App\Ai\Tools\ManageTasksTool;
use App\Ai\Tools\PlanTasksTool;
use App\Ai\Tools\PrepareBrowserFillTool;
use App\Ai\Tools\ProposeApplicationChangesTool;
use App\Ai\Tools\ProposeCollegeListAdditionsTool;
use App\Ai\Tools\ProposeCollegeListChangesTool;
use App\Ai\Tools\ProposeDocumentValueDecisionsTool;
use App\Ai\Tools\ProposeProfileChangesTool;
use App\Ai\Tools\ReadApplicationTool;
use App\Ai\Tools\ReadCollegeListTool;
use App\Ai\Tools\ReadCollegeRequirementsTool;
use App\Ai\Tools\ReadPendingDocumentValuesTool;
use App\Ai\Tools\ReadStudentProfileTool;
use App\Ai\Tools\ReadTasksTool;
use App\Ai\Tools\RequestExportTool;
use App\Ai\Tools\RunCollegeListAgentTool;
use App\Ai\Tools\SearchMyDocumentsTool;
use App\Ai\Tools\StartCollegeListCouncilJobTool;
use App\Ai\Tools\StartEssayRoastTool;
use App\Ai\Tools\StartEssayRunTool;
use App\Ai\Tools\StartGapFixTool;
use App\Ai\Tools\StartWhyThisSchoolTool;
use App\Models\User;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\WebSearch;
use LogicException;

/**
 * The server-side permission boundary for the mentor: the tool list a run receives IS what it may do.
 * The model cannot call a tool that is not in the list — the SDK rejects unknown tool calls.
 */
final class MentorToolPolicy
{
    /**
     * @return list<Tool|WebSearch>
     */
    public function toolsFor(User $student, ChatMode $mode, ?string $conversationId = null): array
    {
        $tools = match ($mode) {
            ChatMode::Ask => $this->askTools($student),
            ChatMode::Agent => $this->agentTools($student, $conversationId),
        };

        if ($mode === ChatMode::Ask) {
            $this->assertReadOnly($tools);
        }

        return $tools;
    }

    /**
     * @return list<Tool|WebSearch>
     */
    private function askTools(User $student): array
    {
        $tools = [
            new ReadStudentProfileTool($student),
            new ReadCollegeListTool($student),
            new ReadApplicationTool($student),
            new ReadCollegeRequirementsTool($student),
            new SearchMyDocumentsTool($student),
            new GetActiveJobsTool($student),
            new GetFeatureQuotaTool($student),
            new ReadTasksTool($student),
            new ReadPendingDocumentValuesTool($student),
        ];

        if (config('college_list_council.enabled')) {
            $tools[] = new GetCollegeListRunArtifactsTool($student);
        }

        return [...$tools, ...$this->subagents(), ...$this->webSearch()];
    }

    /**
     * Agent tools. Profile, list, application and document-value changes are proposals the student approves
     * (decisions D-011); essay/gap work creates task-local artifacts, which the plan allows without approval, and
     * background plans are started, paused, resumed, retried or cancelled directly on the student's request.
     * The Browser tools work in the one tab the student gave the mentor from the extension's side panel, while
     * the panel is open (the panel shows every action, asks for Allow before a consequential click, and can stop it).
     *
     * @return list<Tool|WebSearch>
     */
    private function agentTools(User $student, ?string $conversationId): array
    {
        $tools = [
            new GetStudentAdmissionContextTool($student),
            new GetStudentCollegeList($student),
            new ReadStudentProfileTool($student),
            new ReadApplicationTool($student),
            new ReadCollegeRequirementsTool($student),
            new SearchMyDocumentsTool($student),
            new GetActiveJobsTool($student),
            new GetFeatureQuotaTool($student),
            new ProposeProfileChangesTool($student, $conversationId),
            new ProposeCollegeListAdditionsTool($student, $conversationId),
            new ProposeCollegeListChangesTool($student, $conversationId),
            new ProposeApplicationChangesTool($student, $conversationId),
            new ReadPendingDocumentValuesTool($student),
            new ProposeDocumentValueDecisionsTool($student, $conversationId),
            new PlanTasksTool($student, $conversationId),
            new ReadTasksTool($student),
            new ManageTasksTool($student, $conversationId),
            new RequestExportTool($student),
            new PrepareBrowserFillTool($student, $conversationId),
            new BrowserOpenTool($student),
            new BrowserReadTool($student),
            new BrowserFillTool($student),
            new BrowserClickTool($student),
            new BrowserPressKeyTool($student),
            new StartEssayRunTool($student),
            new StartGapFixTool($student),
            new StartEssayRoastTool($student),
            new StartWhyThisSchoolTool($student),
        ];

        if (config('college_list_council.enabled')) {
            $tools[] = new StartCollegeListCouncilJobTool($student);
            $tools[] = new GetCollegeListRunArtifactsTool($student);
        }

        if (! config('college_list_council.enabled') || config('college_list_council.sync_fallback')) {
            $tools[] = new RunCollegeListAgentTool($student);
        }

        return [...$tools, ...$this->subagents(), ...$this->webSearch()];
    }

    /**
     * Specialists the mentor can delegate a self-contained task to; the chat shows each one working. They see
     * only the task text and change nothing, so both modes get them.
     *
     * @return list<SubagentTool>
     */
    private function subagents(): array
    {
        $agents = [new ApplicationPlannerSubagent, new WritingCoachSubagent];

        if (config('admissions.general_agent_web_search', true)) {
            array_unshift($agents, new ResearcherSubagent, new CollegeScoutSubagent);
        }

        // Wrapped so the chat sees each specialist's searches as well as its text.
        return array_map(fn (MentorSubagent $agent) => new SubagentTool($agent), $agents);
    }

    /**
     * Provider-side web search reads public pages only; results are untrusted data.
     *
     * @return list<WebSearch>
     */
    private function webSearch(): array
    {
        return config('admissions.general_agent_web_search', true) ? [(new WebSearch)->max(5)] : [];
    }

    /**
     * @param  list<object>  $tools
     */
    private function assertReadOnly(array $tools): void
    {
        foreach ($tools as $tool) {
            if (! $tool instanceof ReadOnlyTool && ! $tool instanceof WebSearch) {
                throw new LogicException('Ask mode may only receive read-only tools; '.$tool::class.' is not one.');
            }
        }
    }
}
