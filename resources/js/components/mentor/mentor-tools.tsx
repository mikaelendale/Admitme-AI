import {
    Browsers,
    Buildings,
    CalendarCheck,
    ClipboardText,
    Compass,
    CursorClick,
    Export,
    FileMagnifyingGlass,
    FileText,
    Fire,
    Gauge,
    Globe,
    Kanban,
    Keyboard,
    ListBullets,
    ListChecks,
    ListPlus,
    MagnifyingGlass,
    PenNib,
    PencilSimpleLine,
    Pulse,
    Target,
    UserCircle,
    UsersThree,
    Wrench,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';

type ToolCopy = { running: string; done: string; icon: Icon };

/** How each mentor tool reads in the chat, while it runs and once it is done. */
const TOOLS: Record<string, ToolCopy> = {
    ReadStudentProfileTool: {
        running: 'Reading your profile',
        done: 'Read your profile',
        icon: UserCircle,
    },
    GetStudentAdmissionContextTool: {
        running: 'Reading your profile',
        done: 'Read your profile',
        icon: UserCircle,
    },
    ReadCollegeListTool: {
        running: 'Loading your college list',
        done: 'Loaded your college list',
        icon: ListBullets,
    },
    GetStudentCollegeList: {
        running: 'Loading your college list',
        done: 'Loaded your college list',
        icon: ListBullets,
    },
    ReadApplicationTool: {
        running: 'Checking your applications',
        done: 'Checked your applications',
        icon: ClipboardText,
    },
    ReadCollegeRequirementsTool: {
        running: 'Checking college requirements',
        done: 'Checked college requirements',
        icon: Buildings,
    },
    SearchMyDocumentsTool: {
        running: 'Searching your documents',
        done: 'Searched your documents',
        icon: FileMagnifyingGlass,
    },
    GetActiveJobsTool: {
        running: 'Checking background work',
        done: 'Checked background work',
        icon: Pulse,
    },
    GetFeatureQuotaTool: {
        running: 'Checking your plan limits',
        done: 'Checked your plan limits',
        icon: Gauge,
    },
    ReadTasksTool: {
        running: 'Checking your tasks',
        done: 'Checked your tasks',
        icon: ListChecks,
    },
    ProposeProfileChangesTool: {
        running: 'Preparing profile changes',
        done: 'Prepared profile changes',
        icon: PencilSimpleLine,
    },
    ProposeCollegeListAdditionsTool: {
        running: 'Preparing colleges to add',
        done: 'Prepared colleges to add',
        icon: ListPlus,
    },
    ProposeCollegeListChangesTool: {
        running: 'Preparing list changes',
        done: 'Prepared list changes',
        icon: ListChecks,
    },
    ProposeApplicationChangesTool: {
        running: 'Preparing application changes',
        done: 'Prepared application changes',
        icon: ClipboardText,
    },
    ReadPendingDocumentValuesTool: {
        running: 'Checking values from your documents',
        done: 'Checked values from your documents',
        icon: FileMagnifyingGlass,
    },
    ProposeDocumentValueDecisionsTool: {
        running: 'Preparing document values',
        done: 'Prepared document values',
        icon: FileText,
    },
    PlanTasksTool: {
        running: 'Planning background tasks',
        done: 'Started background tasks',
        icon: Kanban,
    },
    ManageTasksTool: {
        running: 'Updating a background task',
        done: 'Updated a background task',
        icon: Kanban,
    },
    RequestExportTool: {
        running: 'Preparing your export',
        done: 'Prepared your export',
        icon: Export,
    },
    PrepareBrowserFillTool: {
        running: 'Preparing a form fill',
        done: 'Prepared a form fill',
        icon: Browsers,
    },
    BrowserOpenTool: {
        running: 'Opening a page in your browser',
        done: 'Opened a page in your browser',
        icon: Browsers,
    },
    BrowserReadTool: {
        running: 'Reading the page',
        done: 'Read the page',
        icon: Browsers,
    },
    BrowserFillTool: {
        running: 'Filling in fields',
        done: 'Filled in fields',
        icon: PencilSimpleLine,
    },
    BrowserClickTool: {
        running: 'Clicking on the page',
        done: 'Clicked on the page',
        icon: CursorClick,
    },
    BrowserPressKeyTool: {
        running: 'Pressing a key',
        done: 'Pressed a key',
        icon: Keyboard,
    },
    StartEssayRunTool: {
        running: 'Starting essay work',
        done: 'Started essay work',
        icon: PenNib,
    },
    StartWhyThisSchoolTool: {
        running: 'Drafting Why This School',
        done: 'Drafted Why This School',
        icon: PenNib,
    },
    StartEssayRoastTool: {
        running: 'Roasting your essay',
        done: 'Roasted your essay',
        icon: Fire,
    },
    StartGapFixTool: {
        running: 'Analyzing gaps',
        done: 'Analyzed gaps',
        icon: Target,
    },
    StartCollegeListCouncilJobTool: {
        running: 'Starting your list build',
        done: 'Started your list build',
        icon: UsersThree,
    },
    RunCollegeListAgentTool: {
        running: 'Building your list',
        done: 'Built your list',
        icon: UsersThree,
    },
    UpsertStudentCollegeList: {
        running: 'Updating your list',
        done: 'Updated your list',
        icon: ListChecks,
    },
    GetCollegeListRunArtifactsTool: {
        running: 'Checking list results',
        done: 'Checked list results',
        icon: ListChecks,
    },
    web_search: {
        running: 'Searching the web',
        done: 'Searched the web',
        icon: Globe,
    },
    WebSearch: {
        running: 'Searching the web',
        done: 'Searched the web',
        icon: Globe,
    },
};

const FALLBACK: ToolCopy = {
    running: 'Working',
    done: 'Used a tool',
    icon: Wrench,
};

export function toolCopy(name: string): ToolCopy {
    return TOOLS[name] ?? FALLBACK;
}

/** A short note on what a browser tool worked on (the page it opened, how many fields it filled). */
export function toolDetail(name: string, input: unknown): string | null {
    const args = (input && typeof input === 'object' ? input : {}) as Record<
        string,
        unknown
    >;

    if (name === 'BrowserOpenTool' && typeof args.url === 'string') {
        try {
            const url = new URL(args.url);
            const path = url.pathname === '/' ? '' : url.pathname;

            return `${url.host}${path}`.slice(0, 80);
        } catch {
            return null;
        }
    }

    if (name === 'BrowserFillTool' && Array.isArray(args.fields)) {
        return args.fields.length === 1
            ? '1 field'
            : `${args.fields.length} fields`;
    }

    if (name === 'BrowserPressKeyTool' && typeof args.key === 'string') {
        return args.key;
    }

    return null;
}

type SubagentLook = { label: string; icon: Icon; tile: string };

/** The specialists the mentor delegates to (App\Ai\Agents\Subagents), each with its own mark. */
const SUBAGENTS: Record<string, SubagentLook> = {
    researcher: {
        label: 'Researcher',
        icon: MagnifyingGlass,
        tile: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    },
    college_scout: {
        label: 'College scout',
        icon: Compass,
        tile: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    application_planner: {
        label: 'Application planner',
        icon: CalendarCheck,
        tile: 'bg-amber-500/12 text-amber-600 dark:text-amber-400',
    },
    writing_coach: {
        label: 'Writing coach',
        icon: PenNib,
        tile: 'bg-pink-500/10 text-pink-500 dark:text-pink-400',
    },
};

export function subagentLook(name: string): SubagentLook | null {
    return SUBAGENTS[name] ?? null;
}

export function isSubagent(name: string): boolean {
    return name in SUBAGENTS;
}
