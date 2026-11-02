/**
 * Mentor browser control — contract v1, shared by the AdmitMe server, the side panel and the content script.
 *
 * The student gives the mentor ONE tab ("Let the mentor use this browser" in the side panel). While the side
 * panel is open, the mentor can open pages in that tab, read them, fill fields, click and press keys. The
 * student stays in charge:
 *   - the panel shows every action as it happens and has a Stop button (closes the session at once);
 *   - passwords are never read or typed, file inputs are never set, CAPTCHAs are left to the student;
 *   - a click on a consequential control (submit, pay, sign, certify, send, delete…) runs only after the
 *     student presses Allow in the panel; otherwise the result is `declined`.
 * Everything the mentor sends is data for packaged handlers below — never JavaScript.
 *
 * HTTP API (bearer token from linking, JSON):
 *   POST   /api/browser/agent-sessions                       { tab_id, url? }      → 201 { session: AgentSession }
 *          Starts control of that tab. Ends any other open agent session of this student.
 *   GET    /api/browser/agent-sessions/{session}/next?tab_id=&url=
 *          The panel's poll (also its heartbeat; `url` = the tab's current URL). → 200 { status: 'ok', command: AgentCommand | null, session: AgentSession }
 *          → 404 if the session is not this device's; → 409 { status: 'wrong_tab' } if tab_id differs;
 *          → 410 { status: 'closed' } once the session was stopped (from the panel, the web app or by revoking).
 *          Hands out the oldest pending command (one at a time, never while another is leased). A leased command
 *          not reported within its lease (240s: a click may wait up to 3 minutes for the student's Allow) is
 *          failed by the server, never re-run (page actions are not idempotent).
 *   POST   /api/browser/agent-commands/{command}/result      AgentResult           → 200 { command: { id, status } }
 *   DELETE /api/browser/sessions/{session}                   (existing) stops control; pending commands are cancelled.
 */
export const AGENT_CONTRACT_VERSION = 1;

/** How long the panel waits between polls while control is on (it polls again at once after a command). */
export const AGENT_POLL_MS = 700;

/** Limits the content script applies to a page read. */
export const PAGE_LIMITS = {
    elements: 300,
    labelChars: 160,
    valueChars: 500,
    options: 60,
    textChars: 6000,
    headings: 20,
    alerts: 20,
} as const;

export type AgentSession = {
    id: string;
    mode: 'agent';
    tab_id: number;
    status: 'active' | 'waiting_for_browser' | 'closed';
    current_url: string | null;
    last_seen_at: string | null;
};

/** Keys the mentor may press (synthetic key events on the focused or given element; never form submission by Enter). */
export type AgentKey =
    'Enter' | 'Tab' | 'Escape' | 'ArrowDown' | 'ArrowUp' | 'Space' | 'Backspace';

export type AgentCommand =
    /** Navigate the controlled tab (http/https only). Result: { page } after the page has loaded and settled. */
    | { id: string; type: 'agent_open'; payload: { url: string } }
    /** Read the page. `text: false` leaves out the visible-text excerpt. Result: { page }. */
    | { id: string; type: 'agent_read'; payload: { text?: boolean } }
    /**
     * Set several fields at once, in order. `value` per kind: text-like/textarea/date ("2027-01-15") → the text;
     * select → an option's visible text or value (case-insensitive); checkbox → "true" | "false"; radio → "true";
     * combobox (typeahead) → the text to type (then read and click the suggestion). Result: { fields, alerts }.
     */
    | { id: string; type: 'agent_fill'; payload: { fields: { id: string; value: string }[] } }
    /** Click an element (button, link, checkbox, radio, option, tab…). Result: { clicked, navigated, page }. */
    | { id: string; type: 'agent_click'; payload: { id: string } }
    /** Press a key on the element (or the focused one). Result: { page }. */
    | { id: string; type: 'agent_key'; payload: { key: AgentKey; id?: string } };

export type AgentCommandType = AgentCommand['type'];

export type ElementKind =
    | 'text'
    | 'email'
    | 'tel'
    | 'number'
    | 'url'
    | 'date'
    | 'textarea'
    | 'select'
    | 'checkbox'
    | 'radio'
    | 'combobox'
    | 'button'
    | 'link'
    | 'password'
    | 'file'
    | 'other';

export type PageElement = {
    /** Temporary id ("e12"), written on the element as data-admitme-id; valid until the page reloads. */
    id: string;
    kind: ElementKind;
    label: string;
    /** Current value. Never present for password fields; for file inputs only whether a file is chosen. */
    value?: string;
    checked?: boolean;
    /** Select / listbox options (visible texts). */
    options?: string[];
    required?: boolean;
    disabled?: boolean;
    href?: string;
    /** Radio group or fieldset legend the control belongs to. */
    group?: string;
    /** A consequential control (submit, pay, sign, certify, send, delete…): clicking it needs the student's Allow. */
    consequential?: boolean;
    /** The page's validation message for this control, if any. */
    invalid?: string;
};

export type PageModel = {
    url: string;
    title: string;
    /** A visible password field: the student must log in themselves. */
    login_required: boolean;
    /** A visible CAPTCHA widget: the student must complete it. */
    captcha: boolean;
    headings: string[];
    /** role=alert / aria-live messages and visible validation errors. */
    alerts: string[];
    /** Visible text excerpt (absent when the read asked for no text). */
    text?: string;
    elements: PageElement[];
    /** More elements (or text) than the limits allowed. */
    truncated: boolean;
    /** Frames on the page whose content cannot be read (cross-origin iframes). */
    unreadable_frames: number;
};

export type FieldResult = {
    id: string;
    ok: boolean;
    /** What the field reads after setting it (never for passwords). */
    value?: string;
    error?: string;
};

export type AgentResultData = {
    page?: PageModel;
    fields?: FieldResult[];
    alerts?: string[];
    clicked?: string;
    navigated?: boolean;
};

/** POST /api/browser/agent-commands/{command}/result */
export type AgentResult =
    | { outcome: 'done'; data: AgentResultData }
    | { outcome: 'failed'; message: string; data?: AgentResultData }
    /** The student pressed Deny on a consequential click (or did not answer within 3 minutes). */
    | { outcome: 'declined'; message?: string };

/** Messages from the side panel to the content script (agent control). */
export type AgentContentMessage =
    | { type: 'admitme.agent.read'; version: number; text: boolean }
    | {
          type: 'admitme.agent.fill';
          version: number;
          fields: { id: string; value: string }[];
      }
    /** `confirmed` is set by the panel after the student pressed Allow. */
    | {
          type: 'admitme.agent.click';
          version: number;
          id: string;
          confirmed: boolean;
      }
    | { type: 'admitme.agent.key'; version: number; key: AgentKey; id?: string };

/** The content script's answer to admitme.agent.click when the control needs the student's Allow first. */
export type ClickAnswer =
    | { ok: true; clicked: string }
    | { ok: false; needs_confirmation: true; label: string }
    | { ok: false; error: string };

/** The content script's answer to admitme.agent.fill: the `fields` and `alerts` of the command's result. */
export type FillAnswer = { fields: FieldResult[]; alerts: string[] };

/** The content script's answer to admitme.agent.key. */
export type KeyAnswer = { ok: true } | { ok: false; error: string };
