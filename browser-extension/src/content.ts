/**
 * Content script with two sets of packaged handlers. Messages carry data only (element ids, values, key
 * names); nothing the server sends is ever run as code.
 *
 *  - Approved fill (Phase 10, shared/commands.ts): observes visible form fields and performs approved
 *    fill_field / select_option steps, reading each value back. It never clicks or submits.
 *  - Mentor browser control (shared/agent.ts): reads the page into a PageModel, then fills, clicks and presses
 *    keys on the elements it numbered. Passwords are never read or typed, file inputs are never set, CAPTCHAs
 *    are left to the student, and a consequential control (submit, pay, sign, certify…) is clicked only after
 *    the student pressed Allow in the side panel.
 *
 * Self-contained (type-only imports) so it loads as a classic script.
 */
import type {
    AGENT_CONTRACT_VERSION as ContractVersion,
    AgentContentMessage,
    AgentKey,
    ClickAnswer,
    ElementKind,
    FieldResult,
    FillAnswer,
    KeyAnswer,
    PAGE_LIMITS as ContractLimits,
    PageElement,
    PageModel,
} from './shared/agent';
import type {
    ContentMessage,
    LeasedCommand,
    Observation,
    ObservedField,
    PerformResult,
} from './shared/commands';

// ---------------------------------------------------------------------------------------------------------
// Approved fill (Phase 10)
// ---------------------------------------------------------------------------------------------------------

const SKIPPED_TYPES = [
    'password',
    'hidden',
    'file',
    'submit',
    'button',
    'image',
    'reset',
];

type FieldElement = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

function typeOf(el: FieldElement): string {
    if (el instanceof HTMLSelectElement) {
        return 'select';
    }

    if (el instanceof HTMLTextAreaElement) {
        return 'textarea';
    }

    return (el.getAttribute('type') ?? 'text').toLowerCase();
}

function labelFor(el: FieldElement): string {
    const aria = el.getAttribute('aria-label');

    if (aria) {
        return aria.trim();
    }

    if (el.id) {
        const label = document.querySelector(
            'label[for="' + CSS.escape(el.id) + '"]',
        );

        if (label?.textContent) {
            return label.textContent.trim();
        }
    }

    const wrapping = el.closest('label');

    if (wrapping?.textContent) {
        return wrapping.textContent.trim();
    }

    return (
        el.getAttribute('placeholder') ??
        el.getAttribute('name') ??
        ''
    ).trim();
}

function visibleFields(): FieldElement[] {
    return Array.from(
        document.querySelectorAll<FieldElement>('input, select, textarea'),
    ).filter(
        (el) =>
            !SKIPPED_TYPES.includes(typeOf(el)) &&
            !el.disabled &&
            (el as HTMLElement).offsetParent !== null,
    );
}

function observe(): Observation {
    const elements = visibleFields();
    let next =
        elements.reduce(
            (max, el) =>
                Math.max(max, Number(el.dataset.admitmeField?.slice(1) ?? 0)),
            0,
        ) + 1;

    const observed: ObservedField[] = elements.map((el) => {
        // Temporary ids live in the page for this load only; a reload means observing again.
        if (!el.dataset.admitmeField) {
            el.dataset.admitmeField = 'f' + next;
            next += 1;
        }

        return {
            field_id: el.dataset.admitmeField,
            label: labelFor(el),
            name: el.getAttribute('name') ?? '',
            type: typeOf(el),
            value: el.value,
            options:
                el instanceof HTMLSelectElement
                    ? Array.from(el.options).map((o) => o.value)
                    : [],
            required: el.required,
        };
    });

    return { url: location.href, origin: location.origin, fields: observed };
}

function setValue(el: FieldElement, value: string): void {
    // The native setter, so pages that track input values see the change; then the events they listen for.
    const proto =
        el instanceof HTMLSelectElement
            ? HTMLSelectElement.prototype
            : el instanceof HTMLTextAreaElement
              ? HTMLTextAreaElement.prototype
              : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value')?.set?.call(el, value);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
}

function perform(command: LeasedCommand): PerformResult {
    const el = document.querySelector<FieldElement>(
        '[data-admitme-field="' + CSS.escape(command.field_id) + '"]',
    );

    if (!el) {
        return {
            outcome: 'failed',
            observed_value: null,
            message: 'That field is no longer on the page.',
        };
    }

    // Reconciling a lost acknowledgement: if it already holds the value, only confirm it.
    if (command.reconcile && el.value === command.value) {
        return { outcome: 'done', observed_value: el.value };
    }

    if (command.type === 'select_option') {
        if (
            !(el instanceof HTMLSelectElement) ||
            !Array.from(el.options).some((o) => o.value === command.value)
        ) {
            return {
                outcome: 'failed',
                observed_value: el.value,
                message: 'That option is not available here.',
            };
        }

        setValue(el, command.value);
    } else if (command.type === 'fill_field') {
        setValue(el, command.value);
    } else {
        // No clicking on arbitrary pages — and never submitting for the student.
        return {
            outcome: 'failed',
            observed_value: null,
            message: 'This action is not supported on this page.',
        };
    }

    return { outcome: 'done', observed_value: el.value };
}

// ---------------------------------------------------------------------------------------------------------
// Mentor browser control: shared helpers
// ---------------------------------------------------------------------------------------------------------

/** The contract's constants, repeated so this file stays a classic script; their types make tsc reject drift. */
const AGENT_VERSION: typeof ContractVersion = 1;
const LIMITS: typeof ContractLimits = {
    elements: 300,
    labelChars: 160,
    valueChars: 500,
    options: 60,
    textChars: 6000,
    headings: 20,
    alerts: 20,
};

const ID_ATTR = 'data-admitme-id';
const ALERT_CHARS = 300;
/** Pages validate and re-render after input events; read values back only after this pause. */
const FILL_SETTLE_MS = 250;
/** Same-origin frames inside frames inside frames… are read only this deep. */
const MAX_FRAME_DEPTH = 3;

const INTERACTIVE = [
    'input',
    'select',
    'textarea',
    'button',
    'a[href]',
    'summary',
    '[role="button"]',
    '[role="link"]',
    '[role="checkbox"]',
    '[role="radio"]',
    '[role="combobox"]',
    '[role="option"]',
    '[role="menuitem"]',
    '[role="tab"]',
    '[role="switch"]',
    '[role="listbox"]',
    '[contenteditable]:not([contenteditable="false"])',
].join(', ');
const HEADINGS = 'h1, h2, h3, [role="heading"]';
const LIVE_REGIONS =
    '[role="alert"], [role="status"], [aria-live]:not([aria-live="off"])';
const ERRORISH = '[class*="error" i], [class*="invalid" i]';
const FORM_CONTROLS = 'input:not([type="hidden"]), select, textarea, button';
/** Never part of a label or a control's own text. */
const NO_TEXT =
    'select, option, optgroup, datalist, textarea, input, script, style, noscript, template, svg, [aria-hidden="true"], [hidden]';
const CAPTCHA_SRC =
    /recaptcha|hcaptcha|challenges\.cloudflare\.com|turnstile|arkoselabs|funcaptcha|captcha/i;
const CAPTCHA_TOKEN =
    '[name="g-recaptcha-response"], [name="h-captcha-response"], [name="cf-turnstile-response"]';

/**
 * Final actions: clicking one of these (or ticking a box that says it) needs the student's Allow.
 * "Save", "Next", "Continue" or "Save and continue" are not on the list, even on a type=submit button.
 */
const CONSEQUENTIAL =
    /\b(?:(?:re)?submit|pay|payment|purchase|buy|checkout|check out|place (?:my |your |the )?order|sign|e-?sign(?:ature)?|signature|certify|attest|send|delete|remove|withdraw|finali[sz]e|complete (?:my |your |the )?application|confirm and submit|enroll|(?:accept|decline) (?:the |my |your |this )?(?:offer|admission))\b/i;
/** Phrases that contain a word from the list but are not final actions; removed before matching. */
const NOT_CONSEQUENTIAL =
    /\bsign(?:ing)?[\s-]?(?:in|up|on|out)\b|\bsign(?:in|up|on|out)\b|\bsubmit (?:a |your )?(?:search|query|question|feedback)\b/gi;
/** For a checkbox or radio, its group's question counts too when it is an attestation. */
const ATTESTATION = /\b(?:certify|attest|e-?sign(?:ature)?|signature|sign)\b/i;
/** A typed signature is the student's own act, like a password. */
const SIGNATURE =
    /\b(?:e-?)?signature\b|\bsign (?:here|your (?:full |legal )?name)\b/i;

const CONFIRMATION_NEEDED =
    "This needs the student's confirmation — click it instead (the panel will ask them)";

type Root = Document | ShadowRoot;
type TextField = HTMLInputElement | HTMLTextAreaElement;

type Scan = {
    candidates: Element[];
    headings: Element[];
    live: Element[];
    errorish: Element[];
    captchaHints: Element[];
    /** Frames whose document cannot be read (cross-origin). */
    sealedFrames: Element[];
    maxId: number;
    seen: Set<string>;
};

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function collapse(value: string | null | undefined): string {
    return (value ?? '').replace(/\s+/g, ' ').trim();
}

function clip(value: string, max: number): string {
    return value.length > max ? value.slice(0, max - 1).trimEnd() + '…' : value;
}

function quote(value: string, max = 80): string {
    return `“${clip(collapse(value), max)}”`;
}

function matchesSafe(el: Element, selector: string): boolean {
    try {
        return el.matches(selector);
    } catch {
        return false;
    }
}

function styleOf(el: Element): CSSStyleDeclaration | null {
    try {
        return (el.ownerDocument.defaultView ?? window).getComputedStyle(el);
    } catch {
        return null;
    }
}

/** Rendered (has boxes), not visibility:hidden, and not inside aria-hidden / inert content. */
function isVisible(el: Element, ignoreAriaHidden = false): boolean {
    if (el.getClientRects().length === 0) {
        return false;
    }

    const style = styleOf(el);

    if (
        style &&
        (style.visibility === 'hidden' || style.visibility === 'collapse')
    ) {
        return false;
    }

    return (
        ignoreAriaHidden || el.closest('[aria-hidden="true"], [inert]') === null
    );
}

function frameDocument(frame: Element): Document | null {
    try {
        return (frame as HTMLIFrameElement).contentDocument;
    } catch {
        return null;
    }
}

function isFrame(el: Element): boolean {
    return el.localName === 'iframe' || el.localName === 'frame';
}

/** Visible text of an element, without form controls' contents and hidden parts. */
function textOf(node: Element, exclude?: Element): string {
    const rendered = node.getClientRects().length > 0;
    const parts: string[] = [];
    let total = 0;
    const walker = node.ownerDocument.createTreeWalker(
        node,
        NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT,
        {
            acceptNode(current) {
                if (current.nodeType === Node.TEXT_NODE) {
                    return NodeFilter.FILTER_ACCEPT;
                }

                const el = current as Element;

                if (el === exclude || matchesSafe(el, NO_TEXT)) {
                    return NodeFilter.FILTER_REJECT;
                }

                // Inside a rendered element, a part without boxes is display:none (unless display:contents).
                if (
                    rendered &&
                    el.getClientRects().length === 0 &&
                    styleOf(el)?.display !== 'contents'
                ) {
                    return NodeFilter.FILTER_REJECT;
                }

                return NodeFilter.FILTER_SKIP;
            },
        },
    );

    while (walker.nextNode() && total < 2000) {
        const value = walker.currentNode.nodeValue ?? '';

        if (value.trim() !== '') {
            parts.push(value);
            total += value.length;
        }
    }

    return collapse(parts.join(' '));
}

/** The text of the elements an attribute such as aria-labelledby points to. */
function textByIds(el: Element, attribute: string): string {
    const ids = collapse(el.getAttribute(attribute)).split(' ').filter(Boolean);

    if (ids.length === 0) {
        return '';
    }

    // Duck-typed: a document or shadow root from a same-origin frame is not an instance of this realm's classes.
    const root = el.getRootNode() as Partial<Document>;
    const lookup = (id: string): Element | null =>
        typeof root.getElementById === 'function'
            ? root.getElementById(id)
            : el.ownerDocument.getElementById(id);

    return collapse(
        ids
            .map((id) => {
                const target = lookup(id);

                return target
                    ? textOf(target) ||
                          collapse(target.getAttribute('aria-label'))
                    : '';
            })
            .join(' '),
    );
}

function roleOf(el: Element): string {
    return collapse(el.getAttribute('role')).toLowerCase().split(' ')[0] ?? '';
}

// ---------------------------------------------------------------------------------------------------------
// Reading the page
// ---------------------------------------------------------------------------------------------------------

const INPUT_KINDS: Record<string, ElementKind> = {
    text: 'text',
    search: 'text',
    email: 'email',
    tel: 'tel',
    number: 'number',
    range: 'number',
    url: 'url',
    date: 'date',
    'datetime-local': 'date',
    month: 'date',
    week: 'date',
    time: 'date',
    password: 'password',
    file: 'file',
    checkbox: 'checkbox',
    radio: 'radio',
    submit: 'button',
    button: 'button',
    reset: 'button',
    image: 'button',
    color: 'other',
};

const ROLE_KINDS: Record<string, ElementKind> = {
    button: 'button',
    link: 'link',
    checkbox: 'checkbox',
    switch: 'checkbox',
    menuitemcheckbox: 'checkbox',
    radio: 'radio',
    menuitemradio: 'radio',
    combobox: 'combobox',
    option: 'button',
    menuitem: 'button',
    tab: 'button',
    listbox: 'select',
    textbox: 'text',
    searchbox: 'text',
};

const TOGGLE_ROLES = [
    'checkbox',
    'switch',
    'radio',
    'menuitemcheckbox',
    'menuitemradio',
];

const FIELD_KINDS: ElementKind[] = [
    'text',
    'email',
    'tel',
    'number',
    'url',
    'date',
    'textarea',
    'select',
    'checkbox',
    'radio',
    'combobox',
    'password',
    'file',
];

/** Kinds whose click is checked against the consequential list. */
const CLICKABLE_KINDS: ElementKind[] = [
    'button',
    'link',
    'checkbox',
    'radio',
    'other',
];

const TEXT_KINDS: ElementKind[] = [
    'text',
    'email',
    'tel',
    'number',
    'url',
    'date',
    'textarea',
    'combobox',
];

function kindOf(el: Element): ElementKind {
    const role = roleOf(el);
    const local = el.localName;

    if (local === 'input') {
        const kind = INPUT_KINDS[(el as HTMLInputElement).type] ?? 'text';
        const autocomplete = el.getAttribute('aria-autocomplete') ?? '';

        if (
            (kind === 'text' || kind === 'email' || kind === 'url') &&
            (role === 'combobox' ||
                el.hasAttribute('list') ||
                autocomplete === 'list' ||
                autocomplete === 'both')
        ) {
            return 'combobox';
        }

        return kind;
    }

    if (local === 'select') {
        return 'select';
    }

    if (local === 'textarea') {
        return 'textarea';
    }

    if (role !== '' && ROLE_KINDS[role] !== undefined) {
        return ROLE_KINDS[role];
    }

    if (local === 'button' || local === 'summary') {
        return 'button';
    }

    if (local === 'a' && el.hasAttribute('href')) {
        return 'link';
    }

    if ((el as HTMLElement).isContentEditable === true) {
        return 'textarea';
    }

    return 'other';
}

function isEditable(el: Element): boolean {
    return (
        el.localName !== 'input' &&
        el.localName !== 'textarea' &&
        (el as HTMLElement).isContentEditable === true
    );
}

function nativeLabelText(el: Element): string {
    const labels = (el as HTMLInputElement).labels;

    if (!labels || labels.length === 0) {
        return '';
    }

    return collapse(
        Array.from(labels)
            .map((label) => textOf(label, el))
            .join(' '),
    );
}

/**
 * Text right before (or after) a control that has no label: the question in a sibling, as long as no other
 * control sits in between (so a field never takes the previous field's label).
 */
function adjacentText(el: Element, direction: 'previous' | 'next'): string {
    let node: Element | null = el;

    for (let depth = 0; depth < 3 && node; depth += 1) {
        let sibling: ChildNode | null =
            direction === 'previous' ? node.previousSibling : node.nextSibling;

        while (sibling) {
            if (sibling.nodeType === Node.TEXT_NODE) {
                const text = collapse(sibling.nodeValue);

                if (text !== '') {
                    return text;
                }
            } else if (sibling.nodeType === Node.ELEMENT_NODE) {
                const element = sibling as Element;

                if (
                    matchesSafe(element, FORM_CONTROLS) ||
                    element.querySelector(FORM_CONTROLS) !== null
                ) {
                    return '';
                }

                if (!matchesSafe(element, NO_TEXT)) {
                    const text = textOf(element);

                    if (text !== '') {
                        return text;
                    }
                }
            }

            sibling =
                direction === 'previous'
                    ? sibling.previousSibling
                    : sibling.nextSibling;
        }

        const parent: Element | null = node.parentElement;

        // Climb only out of a wrapper that holds this control alone.
        if (
            !parent ||
            matchesSafe(
                parent,
                'form, fieldset, body, [role="group"], [role="radiogroup"]',
            ) ||
            parent.querySelectorAll(FORM_CONTROLS).length > 1
        ) {
            return '';
        }

        node = parent;
    }

    return '';
}

function imageText(el: Element): string {
    return (
        collapse(el.querySelector('img[alt]')?.getAttribute('alt')) ||
        collapse(el.querySelector('svg title')?.textContent)
    );
}

function fallbackLabel(el: Element, kind: ElementKind): string {
    const local = el.localName;
    const attr = (name: string) => collapse(el.getAttribute(name));

    if (local === 'input' && kind === 'button') {
        const type = (el as HTMLInputElement).type;

        return (
            attr('value') ||
            attr('alt') ||
            attr('title') ||
            (type === 'submit' ? 'Submit' : type === 'reset' ? 'Reset' : '') ||
            attr('name')
        );
    }

    if (local === 'input' || local === 'select' || local === 'textarea') {
        const toggle = kind === 'checkbox' || kind === 'radio';

        return (
            nativeLabelText(el) ||
            attr('placeholder') ||
            attr('title') ||
            (toggle
                ? adjacentText(el, 'next') || adjacentText(el, 'previous')
                : adjacentText(el, 'previous')) ||
            attr('name') ||
            (toggle ? attr('value') : '')
        );
    }

    if (isEditable(el)) {
        const label = el.closest('label');

        return (
            attr('aria-placeholder') ||
            attr('data-placeholder') ||
            attr('title') ||
            (label ? textOf(label, el) : '') ||
            adjacentText(el, 'previous')
        );
    }

    // Buttons, links, summaries and ARIA widgets are named by their own text.
    return textOf(el) || imageText(el) || attr('title') || attr('name');
}

function labelOf(el: Element, kind: ElementKind): string {
    const named =
        collapse(el.getAttribute('aria-label')) ||
        textByIds(el, 'aria-labelledby');

    return clip(named || fallbackLabel(el, kind), LIMITS.labelChars);
}

function selectedTexts(el: HTMLSelectElement): string {
    return Array.from(el.selectedOptions)
        .map((option) => collapse(option.text) || collapse(option.value))
        .join(', ');
}

function ariaOptions(el: Element): Element[] {
    const owned = collapse(el.getAttribute('aria-owns'))
        .split(' ')
        .filter(Boolean)
        .map((id) => el.ownerDocument.getElementById(id))
        .filter((owner): owner is HTMLElement => owner !== null);

    return [el, ...owned].flatMap((root) =>
        Array.from(root.querySelectorAll('[role="option"]')),
    );
}

function limitOptions(texts: string[]): string[] {
    const clipped = texts.map((text) => clip(text, LIMITS.labelChars));

    if (clipped.length <= LIMITS.options) {
        return clipped;
    }

    const kept = clipped.slice(0, LIMITS.options - 1);

    // Fill still matches any option by its text; this tells the mentor the list goes on.
    return [...kept, `…and ${clipped.length - kept.length} more options`];
}

function optionsOf(el: Element, kind: ElementKind): string[] | undefined {
    if (el.localName === 'select') {
        return limitOptions(
            Array.from((el as HTMLSelectElement).options).map(
                (option) => collapse(option.text) || collapse(option.value),
            ),
        );
    }

    if (kind === 'select') {
        return limitOptions(ariaOptions(el).map((option) => textOf(option)));
    }

    if (el.localName === 'input' && (el as HTMLInputElement).list) {
        const list = (el as HTMLInputElement).list as HTMLDataListElement;
        const texts = Array.from(list.options)
            .map((option) => collapse(option.value) || collapse(option.label))
            .filter(Boolean);

        return texts.length > 0 ? limitOptions(texts) : undefined;
    }

    return undefined;
}

function valueOf(
    el: Element,
    kind: ElementKind,
    label: string,
): string | undefined {
    const local = el.localName;

    if (kind === 'password') {
        return undefined;
    }

    if (kind === 'file') {
        return ((el as HTMLInputElement).files?.length ?? 0) > 0
            ? 'file chosen'
            : '';
    }

    if (local === 'select') {
        return clip(selectedTexts(el as HTMLSelectElement), LIMITS.valueChars);
    }

    if (
        local === 'textarea' ||
        (local === 'input' &&
            kind !== 'checkbox' &&
            kind !== 'radio' &&
            kind !== 'button')
    ) {
        return clip((el as TextField).value, LIMITS.valueChars);
    }

    if (isEditable(el)) {
        return clip((el as HTMLElement).innerText.trim(), LIMITS.valueChars);
    }

    if (kind === 'select') {
        return clip(
            ariaOptions(el)
                .filter(
                    (option) => option.getAttribute('aria-selected') === 'true',
                )
                .map((option) => textOf(option))
                .join(', '),
            LIMITS.valueChars,
        );
    }

    if (kind === 'combobox') {
        const own = textOf(el);

        return own !== '' && own !== label
            ? clip(own, LIMITS.valueChars)
            : undefined;
    }

    return undefined;
}

function checkedOf(el: Element, kind: ElementKind): boolean | undefined {
    if (el.localName === 'input' && (kind === 'checkbox' || kind === 'radio')) {
        return (el as HTMLInputElement).checked;
    }

    const role = roleOf(el);

    if (TOGGLE_ROLES.includes(role)) {
        return el.getAttribute('aria-checked') === 'true';
    }

    if (
        (role === 'option' || role === 'tab') &&
        el.hasAttribute('aria-selected')
    ) {
        return el.getAttribute('aria-selected') === 'true';
    }

    return undefined;
}

function isRequired(el: Element): boolean {
    return (
        (el as HTMLInputElement).required === true ||
        el.getAttribute('aria-required') === 'true'
    );
}

function isDisabled(el: Element): boolean {
    return (
        matchesSafe(el, ':disabled') ||
        el.getAttribute('aria-disabled') === 'true'
    );
}

/** A link's absolute http(s) address (not for same-page anchors or javascript: links). */
function linkUrl(el: Element): string | null {
    if (el.localName !== 'a') {
        return null;
    }

    const raw = el.getAttribute('href') ?? '';

    if (raw === '' || raw.startsWith('#')) {
        return null;
    }

    try {
        const url = new URL(raw, el.baseURI);

        return url.protocol === 'http:' || url.protocol === 'https:'
            ? url.href
            : null;
    } catch {
        return null;
    }
}

function hrefOf(el: Element): string | undefined {
    const url = linkUrl(el);

    return url === null ? undefined : clip(url, LIMITS.valueChars);
}

function groupOf(el: Element, kind: ElementKind): string {
    const fieldset = el.closest('fieldset');
    const legend = fieldset
        ? Array.from(fieldset.children).find(
              (child) => child.localName === 'legend',
          )
        : undefined;

    if (legend) {
        const text = textOf(legend);

        if (text !== '') {
            return clip(text, LIMITS.labelChars);
        }
    }

    const group = el.parentElement?.closest(
        '[role="radiogroup"], [role="group"]',
    );

    if (group) {
        const text =
            collapse(group.getAttribute('aria-label')) ||
            textByIds(group, 'aria-labelledby');

        if (text !== '') {
            return clip(text, LIMITS.labelChars);
        }
    }

    return kind === 'radio' && el.localName === 'input'
        ? clip(collapse(el.getAttribute('name')), LIMITS.labelChars)
        : '';
}

function isConsequential(
    el: Element,
    kind: ElementKind,
    label: string,
): boolean {
    if (!CLICKABLE_KINDS.includes(kind)) {
        return false;
    }

    const parts = [label, el.getAttribute('title') ?? ''];

    if (el.localName === 'input' && kind === 'button') {
        parts.push((el as HTMLInputElement).value);
    }

    if (kind === 'button' || kind === 'link' || kind === 'other') {
        parts.push(textOf(el));
    }

    if (CONSEQUENTIAL.test(parts.join(' ').replace(NOT_CONSEQUENTIAL, ' '))) {
        return true;
    }

    // "Do you certify…? (•) Yes": the group's question makes the choice an attestation.
    return (
        (kind === 'checkbox' || kind === 'radio') &&
        ATTESTATION.test(groupOf(el, kind).replace(NOT_CONSEQUENTIAL, ' '))
    );
}

function invalidOf(el: Element, kind: ElementKind): string {
    if (kind === 'password') {
        return ''; // its validation message can tell the password's length
    }

    if (el.getAttribute('aria-invalid') === 'true') {
        return clip(
            textByIds(el, 'aria-errormessage') ||
                textByIds(el, 'aria-describedby') ||
                'Invalid',
            LIMITS.labelChars,
        );
    }

    const field = el as HTMLInputElement;

    if (!field.validity || field.validity.valid) {
        return '';
    }

    // An empty required field is only an error once the page shows it as one (after a try to continue).
    if (field.validity.valueMissing && !matchesSafe(el, ':user-invalid')) {
        return '';
    }

    return clip(collapse(field.validationMessage), LIMITS.labelChars);
}

function describe(el: Element, kind: ElementKind): PageElement {
    const label = labelOf(el, kind);
    const item: PageElement = {
        id: el.getAttribute(ID_ATTR) ?? '',
        kind,
        label,
    };
    const value = valueOf(el, kind, label);
    const checked = checkedOf(el, kind);
    const options = optionsOf(el, kind);
    const href = hrefOf(el);
    const group = groupOf(el, kind);
    const invalid = invalidOf(el, kind);

    if (value !== undefined) {
        item.value = value;
    }

    if (checked !== undefined) {
        item.checked = checked;
    }

    if (options !== undefined) {
        item.options = options;
    }

    if (isRequired(el)) {
        item.required = true;
    }

    if (isDisabled(el)) {
        item.disabled = true;
    }

    if (href !== undefined) {
        item.href = href;
    }

    if (group !== '' && group !== label) {
        item.group = group;
    }

    if (isConsequential(el, kind, label)) {
        item.consequential = true;
    }

    if (invalid !== '') {
        item.invalid = invalid;
    }

    return item;
}

function isCaptchaHint(el: Element): boolean {
    const local = el.localName;

    if (isFrame(el)) {
        return (
            CAPTCHA_SRC.test(el.getAttribute('src') ?? '') ||
            /captcha/i.test(el.getAttribute('title') ?? '')
        );
    }

    if (local === 'img' || local === 'input') {
        if (local === 'input' && (el as HTMLInputElement).type === 'hidden') {
            return false;
        }

        return /captcha/i.test(
            [
                el.getAttribute('src'),
                el.getAttribute('alt'),
                el.getAttribute('name'),
                el.id,
                el.getAttribute('placeholder'),
            ].join(' '),
        );
    }

    return false;
}

/** One pass over the page in document order, through open shadow roots and readable same-origin frames. */
function walkRoot(root: Root, scan: Scan, depth: number): void {
    for (const el of Array.from(root.querySelectorAll('*'))) {
        const id = el.getAttribute(ID_ATTR);

        if (id !== null) {
            if (scan.seen.has(id) || !/^e\d+$/.test(id)) {
                // Copied markup (a cloned section) carries another element's id: it gets its own.
                el.removeAttribute(ID_ATTR);
            } else {
                scan.seen.add(id);
                scan.maxId = Math.max(scan.maxId, Number(id.slice(1)));
            }
        }

        if (matchesSafe(el, INTERACTIVE)) {
            scan.candidates.push(el);
        }

        if (matchesSafe(el, HEADINGS)) {
            scan.headings.push(el);
        }

        if (matchesSafe(el, LIVE_REGIONS)) {
            scan.live.push(el);
        } else if (matchesSafe(el, ERRORISH)) {
            scan.errorish.push(el);
        }

        if (isCaptchaHint(el)) {
            scan.captchaHints.push(el);
        }

        if (isFrame(el)) {
            const doc = frameDocument(el);

            if (doc === null) {
                scan.sealedFrames.push(el);
            } else if (depth < MAX_FRAME_DEPTH && isVisible(el)) {
                walkRoot(doc, scan, depth + 1);
            }
        }

        if (el.shadowRoot) {
            walkRoot(el.shadowRoot, scan, depth);
        }
    }
}

function scanPage(): Scan {
    const scan: Scan = {
        candidates: [],
        headings: [],
        live: [],
        errorish: [],
        captchaHints: [],
        sealedFrames: [],
        maxId: 0,
        seen: new Set(),
    };
    walkRoot(document, scan, 0);

    return scan;
}

function includeCandidate(el: Element, ignoreAriaHidden: boolean): boolean {
    if (
        el.localName === 'input' &&
        (el as HTMLInputElement).type === 'hidden'
    ) {
        return false;
    }

    // Only the outermost editable region of a rich-text editor.
    if (
        isEditable(el) &&
        (el.parentElement as HTMLElement | null)?.isContentEditable === true
    ) {
        return false;
    }

    if (isVisible(el, ignoreAriaHidden)) {
        return true;
    }

    // A styled checkbox/radio hides the real input and shows its label instead.
    const type = (el as HTMLInputElement).type;

    return (
        el.localName === 'input' &&
        (type === 'checkbox' || type === 'radio') &&
        Array.from((el as HTMLInputElement).labels ?? []).some((label) =>
            isVisible(label, ignoreAriaHidden),
        )
    );
}

type Chosen = { el: Element; kind: ElementKind; index: number };

function chooseElements(scan: Scan): { chosen: Chosen[]; truncated: boolean } {
    let visible = scan.candidates.filter((el) => includeCandidate(el, false));

    // Some pages leave aria-hidden on their whole content (a modal that never cleaned up): read it anyway.
    if (visible.length === 0 && scan.candidates.length > 0) {
        visible = scan.candidates.filter((el) => includeCandidate(el, true));
    }

    const all: Chosen[] = visible.map((el, index) => ({
        el,
        kind: kindOf(el),
        index,
    }));

    if (all.length <= LIMITS.elements) {
        return { chosen: all, truncated: false };
    }

    // Too many: form fields first, then buttons, then links — and back into page order.
    const rank = (kind: ElementKind) =>
        FIELD_KINDS.includes(kind) ? 0 : kind === 'link' ? 2 : 1;
    const chosen = [...all]
        .sort((a, b) => rank(a.kind) - rank(b.kind) || a.index - b.index)
        .slice(0, LIMITS.elements)
        .sort((a, b) => a.index - b.index);

    return { chosen, truncated: true };
}

function headingsOf(scan: Scan): string[] {
    const out: string[] = [];

    for (const el of scan.headings) {
        if (out.length >= LIMITS.headings) {
            break;
        }

        if (roleOf(el) === 'heading') {
            const level = Number(el.getAttribute('aria-level') ?? '2');

            if (!(level >= 1 && level <= 3)) {
                continue;
            }
        }

        if (!isVisible(el)) {
            continue;
        }

        const text = clip(textOf(el), LIMITS.labelChars);

        if (text !== '' && !out.includes(text)) {
            out.push(text);
        }
    }

    return out;
}

function alertsOf(scan: Scan, invalid: string[]): string[] {
    const out: string[] = [];
    const add = (text: string) => {
        const clipped = clip(collapse(text), ALERT_CHARS);

        if (clipped !== '' && !out.includes(clipped)) {
            out.push(clipped);
        }
    };

    for (const el of scan.live) {
        if (!isVisible(el)) {
            continue;
        }

        const text = textOf(el);

        // role=alert always counts; a polite live region only when it holds a message, not a whole section.
        if (
            roleOf(el) === 'alert' ||
            (text.length <= 400 && el.querySelector(FORM_CONTROLS) === null)
        ) {
            add(text);
        }
    }

    const taken: Element[] = [];

    for (const el of scan.errorish) {
        if (
            taken.some((outer) => outer.contains(el)) ||
            matchesSafe(el, FORM_CONTROLS) ||
            el.querySelector(FORM_CONTROLS) !== null ||
            !isVisible(el)
        ) {
            continue;
        }

        const text = textOf(el);

        // Error messages are short; a long text is a container that only happens to have the class.
        if (text !== '' && text.length <= 400) {
            taken.push(el);
            add(text);
        }
    }

    invalid.forEach(add);

    return out.slice(0, LIMITS.alerts);
}

function isOffscreen(el: Element): boolean {
    const rect = el.getBoundingClientRect();
    const view = el.ownerDocument.defaultView;

    return (
        rect.bottom + (view?.scrollY ?? 0) <= 0 ||
        rect.right + (view?.scrollX ?? 0) <= 0
    );
}

function captchaSolved(frame: Element): boolean {
    let node: Element | null = frame.parentElement;

    for (let depth = 0; depth < 4 && node; depth += 1) {
        const token = node.querySelector<
            HTMLInputElement | HTMLTextAreaElement
        >(CAPTCHA_TOKEN);

        if (token) {
            return token.value.trim() !== '';
        }

        node = node.parentElement;
    }

    return false;
}

function captchaShown(scan: Scan): boolean {
    return scan.captchaHints.some((el) => {
        if (!isVisible(el) || isOffscreen(el)) {
            return false;
        }

        if (!isFrame(el)) {
            return true; // a "type the characters" image or field
        }

        const rect = el.getBoundingClientRect();

        // Invisible reCAPTCHA / hCaptcha and the reCAPTCHA v3 badge need nothing from the student.
        if (
            /invisible/i.test(el.getAttribute('src') ?? '') ||
            el.closest('.grecaptcha-badge') !== null ||
            rect.width < 30 ||
            rect.height < 30
        ) {
            return false;
        }

        return !captchaSolved(el);
    });
}

function unreadableFrames(scan: Scan): number {
    return scan.sealedFrames.filter((frame) => {
        if (
            !isVisible(frame) ||
            CAPTCHA_SRC.test(frame.getAttribute('src') ?? '')
        ) {
            return false;
        }

        const rect = frame.getBoundingClientRect();

        return rect.width >= 2 && rect.height >= 2;
    }).length;
}

function visibleText(): string {
    const lines = (document.body?.innerText ?? '')
        .split('\n')
        .map((line) => line.replace(/[^\S\n]+/g, ' ').trim())
        .filter((line) => line !== '');

    // innerText lists every option of every select; keep only the chosen one (the options are in the elements).
    for (const select of Array.from(document.querySelectorAll('select'))) {
        const options = Array.from(select.options)
            .map((option) => collapse(option.text))
            .filter((text) => text !== '');

        if (options.length < 2) {
            continue;
        }

        const start = lines.findIndex((_, index) =>
            options.every((text, offset) => lines[index + offset] === text),
        );

        if (start >= 0) {
            const chosen = selectedTexts(select);
            lines.splice(start, options.length, ...(chosen ? [chosen] : []));
        }
    }

    return lines.join('\n');
}

function agentRead(withText: boolean): PageModel {
    const scan = scanPage();
    const { chosen, truncated } = chooseElements(scan);

    // Temporary ids: kept while the element lives, new ones numbered after the highest on the page.
    for (const { el } of chosen) {
        if (!el.getAttribute(ID_ATTR)) {
            scan.maxId += 1;
            el.setAttribute(ID_ATTR, 'e' + scan.maxId);
        }
    }

    const elements = chosen.map(({ el, kind }) => describe(el, kind));
    const text = withText ? visibleText() : null;
    const textCut = text !== null && text.length > LIMITS.textChars;

    return {
        url: location.href,
        title: clip(collapse(document.title), LIMITS.labelChars * 2),
        login_required: scan.candidates.some(
            (el) =>
                el.localName === 'input' &&
                (el as HTMLInputElement).type === 'password' &&
                isVisible(el),
        ),
        captcha: captchaShown(scan),
        headings: headingsOf(scan),
        alerts: alertsOf(
            scan,
            elements
                .filter((item) => item.invalid)
                .map((item) => `${item.label || 'A field'}: ${item.invalid}`),
        ),
        ...(text !== null
            ? {
                  text: textCut
                      ? text.slice(0, LIMITS.textChars - 1) + '…'
                      : text,
              }
            : {}),
        elements,
        truncated: truncated || textCut,
        unreadable_frames: unreadableFrames(scan),
    };
}

// ---------------------------------------------------------------------------------------------------------
// Acting on the page
// ---------------------------------------------------------------------------------------------------------

function forEachRoot(
    root: Root,
    depth: number,
    visit: (root: Root) => void,
): void {
    visit(root);

    for (const el of Array.from(root.querySelectorAll('*'))) {
        if (el.shadowRoot) {
            forEachRoot(el.shadowRoot, depth, visit);
        }

        if (isFrame(el) && depth < MAX_FRAME_DEPTH) {
            const doc = frameDocument(el);

            if (doc) {
                forEachRoot(doc, depth + 1, visit);
            }
        }
    }
}

function findById(id: unknown): Element | null {
    if (typeof id !== 'string' || !/^e\d+$/.test(id)) {
        return null;
    }

    const selector = `[${ID_ATTR}="${id}"]`;
    const direct = document.querySelector(selector);

    if (direct) {
        return direct;
    }

    const found: Element[] = [];
    forEachRoot(document, 0, (root) => {
        const match = found.length === 0 ? root.querySelector(selector) : null;

        if (match) {
            found.push(match);
        }
    });

    return found[0] ?? null;
}

const GONE = 'That element is no longer on the page. Read the page again.';

function setNativeValue(el: TextField, value: string): void {
    // The prototype's setter, so pages that wrap the value property (React) still see the change.
    let proto: object | null = Object.getPrototypeOf(el);

    while (proto) {
        const setter = Object.getOwnPropertyDescriptor(proto, 'value')?.set;

        if (setter) {
            setter.call(el, value);

            return;
        }

        proto = Object.getPrototypeOf(proto);
    }

    el.value = value;
}

function focusQuietly(el: Element): void {
    (el as HTMLElement).focus?.({ preventScroll: true });
}

/** A pointer-and-mouse sequence and then a real click(), for widgets that listen to any of them. */
function clickElement(el: Element): void {
    const rect = el.getBoundingClientRect();
    const at = {
        bubbles: true,
        cancelable: true,
        composed: true,
        clientX: rect.left + rect.width / 2,
        clientY: rect.top + rect.height / 2,
        button: 0,
    };
    const pointer = {
        ...at,
        pointerId: 1,
        pointerType: 'mouse',
        isPrimary: true,
    };

    el.dispatchEvent(new PointerEvent('pointerover', pointer));
    el.dispatchEvent(new MouseEvent('mouseover', at));
    el.dispatchEvent(
        new PointerEvent('pointerdown', { ...pointer, buttons: 1 }),
    );
    el.dispatchEvent(new MouseEvent('mousedown', { ...at, buttons: 1 }));
    focusQuietly(el);
    el.dispatchEvent(new PointerEvent('pointerup', pointer));
    el.dispatchEvent(new MouseEvent('mouseup', at));

    if (typeof (el as HTMLElement).click === 'function') {
        (el as HTMLElement).click();
    } else {
        el.dispatchEvent(new MouseEvent('click', at));
    }
}

function normalized(value: string): string {
    return collapse(value).toLowerCase();
}

/** The same text, allowing for case, spacing and the punctuation input masks add ("(555) 123-4567"). */
function sameText(actual: string, wanted: string): boolean {
    if (normalized(actual) === normalized(wanted)) {
        return true;
    }

    const bare = (value: string) =>
        value.toLowerCase().replace(/[^\p{L}\p{N}]/gu, '');

    return bare(wanted) !== '' && bare(actual) === bare(wanted);
}

/** Exact visible text, then exact value, then the shortest text that contains it — all case-insensitive. */
function matchOption(
    texts: string[],
    values: string[],
    wanted: string,
): number {
    const target = normalized(wanted);
    const exactText = texts.findIndex((text) => normalized(text) === target);

    if (exactText >= 0) {
        return exactText;
    }

    const exactValue = values.findIndex(
        (value) => normalized(value) === target,
    );

    if (exactValue >= 0 || target === '') {
        return exactValue;
    }

    let best = -1;
    texts.forEach((text, index) => {
        if (
            normalized(text).includes(target) &&
            (best < 0 || text.length < texts[best].length)
        ) {
            best = index;
        }
    });

    return best;
}

function parseBool(value: string): boolean | null {
    const v = value.trim().toLowerCase();

    if (['true', 'yes', 'on', '1', 'checked', 'x'].includes(v)) {
        return true;
    }

    if (['false', 'no', 'off', '0', 'unchecked', ''].includes(v)) {
        return false;
    }

    return null;
}

function isCaptchaField(el: Element, label: string): boolean {
    return /captcha/i.test(
        [
            label,
            el.getAttribute('name'),
            el.id,
            el.getAttribute('placeholder'),
        ].join(' '),
    );
}

/** A result now, or a read-back to run once the page has settled. */
type Pending = FieldResult | (() => FieldResult);

function fillText(
    id: string,
    el: TextField,
    kind: ElementKind,
    value: string,
): Pending {
    if (el.readOnly) {
        return {
            id,
            ok: false,
            error: 'That field is read-only. It may open a picker: click it instead.',
        };
    }

    if (el.maxLength > 0 && value.length > el.maxLength) {
        return {
            id,
            ok: false,
            error: `Too long: this field takes at most ${el.maxLength} characters (this text has ${value.length}).`,
        };
    }

    focusQuietly(el);
    setNativeValue(el, value);
    el.dispatchEvent(
        new InputEvent('input', {
            bubbles: true,
            composed: true,
            inputType: 'insertText',
            data: value,
        }),
    );

    if (kind === 'combobox') {
        // Typeaheads search on input/keyup; it stays focused so its suggestions stay open.
        el.dispatchEvent(
            new KeyboardEvent('keyup', {
                bubbles: true,
                composed: true,
                key: value.slice(-1) || 'Unidentified',
            }),
        );
    } else {
        el.dispatchEvent(new Event('change', { bubbles: true }));
        el.blur();
    }

    return () => {
        const now = el.value;

        if (sameText(now, value)) {
            return { id, ok: true, value: clip(now, LIMITS.valueChars) };
        }

        const hint =
            kind === 'date'
                ? ' Dates go in as YYYY-MM-DD.'
                : kind === 'number'
                  ? ' Use digits only.'
                  : '';

        return {
            id,
            ok: false,
            value: clip(now, LIMITS.valueChars),
            error:
                now === ''
                    ? `The field did not accept ${quote(value, 60)}.${hint}`
                    : `The field now reads ${quote(now)}.`,
        };
    };
}

function fillSelect(id: string, el: HTMLSelectElement, value: string): Pending {
    const options = Array.from(el.options);
    const index = matchOption(
        options.map((option) => option.text),
        options.map((option) => option.value),
        value,
    );

    if (index < 0) {
        return {
            id,
            ok: false,
            error: `No option matches ${quote(value, 60)}.`,
        };
    }

    if (options[index].disabled) {
        return {
            id,
            ok: false,
            error: `${quote(options[index].text)} cannot be chosen (it is disabled).`,
        };
    }

    focusQuietly(el);

    if (el.multiple) {
        options[index].selected = true;
    } else {
        el.selectedIndex = index;
    }

    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    el.blur();

    return () => {
        const now = selectedTexts(el);

        return options[index].selected
            ? { id, ok: true, value: now }
            : {
                  id,
                  ok: false,
                  value: now,
                  error: `The field now reads ${quote(now)}.`,
              };
    };
}

function fillToggle(
    id: string,
    el: Element,
    kind: ElementKind,
    label: string,
    value: string,
): Pending {
    const want = parseBool(value);

    if (want === null) {
        return { id, ok: false, error: 'Use "true" or "false" for this.' };
    }

    if (kind === 'radio' && !want) {
        return {
            id,
            ok: false,
            error: 'A radio button cannot be cleared: fill the option you want with "true".',
        };
    }

    // Fill has no Allow step, so it never ticks "I certify…" and the like.
    if (isConsequential(el, kind, label)) {
        return { id, ok: false, error: CONFIRMATION_NEEDED };
    }

    const isChecked = () =>
        el.localName === 'input'
            ? (el as HTMLInputElement).checked
            : el.getAttribute('aria-checked') === 'true';

    if (isChecked() !== want) {
        clickElement(el);
    }

    return () => {
        const now = isChecked();

        return now === want
            ? { id, ok: true, value: String(now) }
            : {
                  id,
                  ok: false,
                  value: String(now),
                  error: now
                      ? 'It is still ticked.'
                      : 'It is still not ticked.',
              };
    };
}

function fillEditable(id: string, el: HTMLElement, value: string): Pending {
    const doc = el.ownerDocument;
    let inserted = false;

    focusQuietly(el);

    try {
        // Through the browser's editing commands, so rich-text editors update their own model.
        const range = doc.createRange();
        range.selectNodeContents(el);
        const selection = doc.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
        inserted = doc.execCommand('insertText', false, value);
    } catch {
        inserted = false;
    }

    if (!inserted) {
        el.textContent = value;
        el.dispatchEvent(
            new InputEvent('input', {
                bubbles: true,
                composed: true,
                inputType: 'insertText',
                data: value,
            }),
        );
    }

    return () => {
        const now = el.innerText.trim();

        return sameText(now, value)
            ? { id, ok: true, value: clip(now, LIMITS.valueChars) }
            : {
                  id,
                  ok: false,
                  value: clip(now, LIMITS.valueChars),
                  error: `The field now reads ${quote(now)}.`,
              };
    };
}

function fillListbox(id: string, el: Element, value: string): Pending {
    const options = ariaOptions(el);
    const index = matchOption(
        options.map((option) => textOf(option)),
        options.map((option) => option.getAttribute('data-value') ?? ''),
        value,
    );

    if (index < 0) {
        return {
            id,
            ok: false,
            error: `No option matches ${quote(value, 60)}.`,
        };
    }

    const option = options[index];

    if (option.getAttribute('aria-selected') !== 'true') {
        clickElement(option);
    }

    return () =>
        option.getAttribute('aria-selected') === 'true'
            ? { id, ok: true, value: textOf(option) }
            : {
                  id,
                  ok: false,
                  error: `Clicked ${quote(textOf(option))}, but the list does not show it as chosen.`,
              };
}

function fillOne(id: string, value: string): Pending {
    const el = findById(id);

    if (!el) {
        return { id, ok: false, error: GONE };
    }

    const kind = kindOf(el);
    const fail = (error: string): FieldResult => ({ id, ok: false, error });

    if (kind === 'password') {
        return fail('Passwords are typed by the student.');
    }

    if (kind === 'file') {
        return fail('Files are chosen by the student.');
    }

    const label = labelOf(el, kind);

    if (isCaptchaField(el, label)) {
        return fail('CAPTCHAs are left to the student.');
    }

    if (isDisabled(el)) {
        return fail('That field is disabled.');
    }

    if (TEXT_KINDS.includes(kind) && SIGNATURE.test(label)) {
        return fail('Signatures are typed by the student.');
    }

    const local = el.localName;

    if (local === 'select') {
        return fillSelect(id, el as HTMLSelectElement, value);
    }

    // Native checkboxes/radios and role=checkbox|switch|radio widgets.
    if (kind === 'checkbox' || kind === 'radio') {
        return fillToggle(id, el, kind, label, value);
    }

    if (local === 'input' || local === 'textarea') {
        return kind === 'button'
            ? fail('That is a button: click it instead.')
            : fillText(id, el as TextField, kind, value);
    }

    if (isEditable(el)) {
        return fillEditable(id, el as HTMLElement, value);
    }

    if (kind === 'select') {
        return fillListbox(id, el, value);
    }

    if (kind === 'combobox') {
        return fail(
            'This is a dropdown: click it to open it, then click the option.',
        );
    }

    return fail('This is not a field: click it instead.');
}

async function agentFill(
    fields: { id: string; value: string }[],
): Promise<FillAnswer> {
    const pending: Pending[] = [];
    const touched: Element[] = [];

    for (const field of fields) {
        const id = typeof field?.id === 'string' ? field.id : '';

        if (typeof field?.value !== 'string') {
            pending.push({ id, ok: false, error: 'The value must be text.' });
            continue;
        }

        try {
            const result = fillOne(id, field.value);
            pending.push(result);

            if (typeof result === 'function') {
                const el = findById(id);

                if (el) {
                    touched.push(el);
                }
            }
        } catch (error) {
            pending.push({
                id,
                ok: false,
                error: `Could not fill this field (${error instanceof Error ? error.message : 'unknown error'}).`,
            });
        }
    }

    // Show the student where the mentor is working.
    touched.at(-1)?.scrollIntoView({ block: 'center', inline: 'nearest' });
    await wait(FILL_SETTLE_MS);

    const results = pending.map((result) =>
        typeof result === 'function' ? result() : result,
    );
    const invalid = touched.flatMap((el) => {
        const kind = kindOf(el);
        const message = el.isConnected ? invalidOf(el, kind) : '';

        return message ? [`${labelOf(el, kind) || 'A field'}: ${message}`] : [];
    });

    return { fields: results, alerts: alertsOf(scanPage(), invalid) };
}

/** A link that would open another tab: its http(s) address, so the mentor's tab follows it instead. */
function newTabHref(el: Element): string | null {
    if (el.localName !== 'a') {
        return null;
    }

    const target = collapse(el.getAttribute('target')).toLowerCase();

    if (target === '' || ['_self', '_top', '_parent'].includes(target)) {
        return null;
    }

    return linkUrl(el);
}

function agentClick(id: string, confirmed: boolean): ClickAnswer {
    const el = findById(id);

    if (!el) {
        return { ok: false, error: GONE };
    }

    const kind = kindOf(el);
    const label = labelOf(el, kind) || 'that control';

    if (kind === 'file') {
        return { ok: false, error: 'Files are chosen by the student.' };
    }

    if (isDisabled(el)) {
        return { ok: false, error: `${quote(label)} is disabled right now.` };
    }

    // Into view first, so the student sees what the mentor is about to click (or asks to click).
    el.scrollIntoView({ block: 'center', inline: 'nearest' });

    if (!confirmed && isConsequential(el, kind, label)) {
        return { ok: false, needs_confirmation: true, label };
    }

    const href = newTabHref(el);

    if (href) {
        location.assign(href);
    } else {
        clickElement(el);
    }

    return { ok: true, clicked: label };
}

const KEYS: Record<
    AgentKey,
    { key: string; code: string; keyCode: number; charCode?: number }
> = {
    Enter: { key: 'Enter', code: 'Enter', keyCode: 13, charCode: 13 },
    Tab: { key: 'Tab', code: 'Tab', keyCode: 9 },
    Escape: { key: 'Escape', code: 'Escape', keyCode: 27 },
    ArrowDown: { key: 'ArrowDown', code: 'ArrowDown', keyCode: 40 },
    ArrowUp: { key: 'ArrowUp', code: 'ArrowUp', keyCode: 38 },
    Space: { key: ' ', code: 'Space', keyCode: 32, charCode: 32 },
    Backspace: { key: 'Backspace', code: 'Backspace', keyCode: 8 },
};

function deepActiveElement(): Element | null {
    let active: Element | null = document.activeElement;

    for (let depth = 0; active && depth < 10; depth += 1) {
        const inner: Element | null | undefined = active.shadowRoot
            ? active.shadowRoot.activeElement
            : isFrame(active)
              ? frameDocument(active)?.activeElement
              : null;

        if (!inner || inner === active) {
            break;
        }

        active = inner;
    }

    return active;
}

/** Space activates buttons, checkboxes and radios; Enter activates buttons and links. */
function activatedBy(key: AgentKey, el: Element, kind: ElementKind): boolean {
    if (key === 'Space') {
        return kind === 'button' || kind === 'checkbox' || kind === 'radio';
    }

    if (key === 'Enter') {
        const type =
            el.localName === 'input' ? (el as HTMLInputElement).type : '';

        return (kind === 'button' && type !== 'reset') || kind === 'link';
    }

    return false;
}

/** The button Enter in a form field would press: the form's first submit button. */
function defaultButtonIsConsequential(el: Element): boolean {
    const form = (el as HTMLInputElement).form;
    const button = form?.querySelector(
        'button[type="submit"], button:not([type]), input[type="submit"], input[type="image"]',
    );

    return button
        ? isConsequential(button, 'button', labelOf(button, 'button'))
        : false;
}

function focusNext(from: Element): void {
    const doc = from.ownerDocument;
    const focusable = Array.from(
        doc.querySelectorAll<HTMLElement>(
            'a[href], button, input:not([type="hidden"]), select, textarea, summary, [tabindex], [contenteditable]:not([contenteditable="false"])',
        ),
    ).filter(
        (el) =>
            el.tabIndex >= 0 && !matchesSafe(el, ':disabled') && isVisible(el),
    );
    const next =
        focusable.find(
            (el) =>
                el !== from &&
                (from.compareDocumentPosition(el) &
                    Node.DOCUMENT_POSITION_FOLLOWING) !==
                    0 &&
                !from.contains(el),
        ) ?? null;

    (from as HTMLElement).blur?.();
    next?.focus();
}

function stepSelect(el: HTMLSelectElement, step: number): void {
    let index = el.selectedIndex;

    do {
        index += step;
    } while (
        index >= 0 &&
        index < el.options.length &&
        el.options[index].disabled
    );

    if (index >= 0 && index < el.options.length) {
        el.selectedIndex = index;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function deleteBackward(el: Element): void {
    if (isEditable(el)) {
        el.ownerDocument.execCommand('delete');

        return;
    }

    const field = el as TextField;
    let start: number | null = null;
    let end: number | null = null;

    try {
        start = field.selectionStart;
        end = field.selectionEnd;
    } catch {
        /* email/number inputs have no selection API */
    }

    if (start === null || end === null) {
        setNativeValue(field, field.value.slice(0, -1));
    } else if (start !== end) {
        field.setRangeText('', start, end, 'end');
    } else if (start > 0) {
        field.setRangeText('', start - 1, start, 'end');
    } else {
        return;
    }

    field.dispatchEvent(
        new InputEvent('input', {
            bubbles: true,
            composed: true,
            inputType: 'deleteContentBackward',
        }),
    );
}

function agentKey(key: AgentKey, id: string | undefined): KeyAnswer {
    const spec = KEYS[key];

    if (!spec) {
        return { ok: false, error: `The key ${String(key)} is not supported.` };
    }

    const given = typeof id === 'string' && id !== '';
    const target: Element | null = given
        ? findById(id)
        : (deepActiveElement() ?? document.body);

    if (!target) {
        return {
            ok: false,
            error: given ? GONE : 'Nothing on the page has focus.',
        };
    }

    const kind = kindOf(target);
    const activates = activatedBy(key, target, kind);

    // The student logs in: no Enter (or editing) in a password field.
    if (kind === 'password' && key !== 'Tab' && key !== 'Escape') {
        return { ok: false, error: 'Passwords are typed by the student.' };
    }

    if (activates) {
        const label = labelOf(target, kind) || 'that control';

        if (isConsequential(target, kind, label)) {
            return {
                ok: false,
                error: `${quote(label)} needs the student’s Allow: click it instead.`,
            };
        }
    }

    // Enter in a field could submit the form through the page's own handlers.
    if (
        key === 'Enter' &&
        TEXT_KINDS.includes(kind) &&
        kind !== 'combobox' &&
        kind !== 'textarea' &&
        defaultButtonIsConsequential(target)
    ) {
        return {
            ok: false,
            error: 'Enter here could submit the form, which needs the student’s Allow. Click the form’s button instead.',
        };
    }

    if (given) {
        target.scrollIntoView({ block: 'center', inline: 'nearest' });
        focusQuietly(target);
    }

    const init = {
        key: spec.key,
        code: spec.code,
        keyCode: spec.keyCode,
        which: spec.keyCode,
        bubbles: true,
        cancelable: true,
        composed: true,
    };
    const proceed = target.dispatchEvent(new KeyboardEvent('keydown', init));

    if (proceed && spec.charCode !== undefined) {
        target.dispatchEvent(
            new KeyboardEvent('keypress', {
                ...init,
                keyCode: spec.charCode,
                which: spec.charCode,
                charCode: spec.charCode,
            }),
        );
    }

    // Synthetic key events have no default action; do the harmless ones the student would expect.
    // Never a form submission.
    if (proceed) {
        const local = target.localName;

        if (key === 'Tab') {
            focusNext(target);
        } else if (activates) {
            clickElement(target);
        } else if (
            (key === 'ArrowDown' || key === 'ArrowUp') &&
            local === 'select' &&
            !(target as HTMLSelectElement).multiple
        ) {
            stepSelect(
                target as HTMLSelectElement,
                key === 'ArrowDown' ? 1 : -1,
            );
        } else if (
            key === 'Backspace' &&
            (isEditable(target) ||
                ((local === 'input' || local === 'textarea') &&
                    TEXT_KINDS.includes(kind)))
        ) {
            deleteBackward(target);
        }
    }

    target.dispatchEvent(new KeyboardEvent('keyup', init));

    return { ok: true };
}

// ---------------------------------------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------------------------------------

function failure(error: unknown): { error: string } {
    return {
        error: `The page could not be handled (${error instanceof Error ? error.message : 'unknown error'}).`,
    };
}

function onMessage(
    message: ContentMessage | AgentContentMessage,
    _sender: unknown,
    sendResponse: (response?: unknown) => void,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    switch (message.type) {
        case 'admitme.observe':
            if (message.version === 1) {
                sendResponse(observe());
            }

            return false;

        case 'admitme.perform':
            if (message.version === 1) {
                sendResponse(perform(message.command));
            }

            return false;

        case 'admitme.agent.read':
            if (message.version === AGENT_VERSION) {
                try {
                    sendResponse(agentRead(message.text !== false));
                } catch (error) {
                    sendResponse(failure(error));
                }
            }

            return false;

        case 'admitme.agent.fill':
            if (message.version !== AGENT_VERSION) {
                return false;
            }

            agentFill(Array.isArray(message.fields) ? message.fields : []).then(
                sendResponse,
                (error: unknown) => sendResponse(failure(error)),
            );

            return true; // answers after the page has settled

        case 'admitme.agent.click':
            if (message.version === AGENT_VERSION) {
                try {
                    sendResponse(
                        agentClick(message.id, message.confirmed === true),
                    );
                } catch (error) {
                    sendResponse({ ok: false, error: failure(error).error });
                }
            }

            return false;

        case 'admitme.agent.key':
            if (message.version === AGENT_VERSION) {
                try {
                    sendResponse(agentKey(message.key, message.id));
                } catch (error) {
                    sendResponse({ ok: false, error: failure(error).error });
                }
            }

            return false;
    }

    return false;
}

// The panel injects this file again when a tab has no live copy (opened before the extension was installed or
// reloaded). One listener per page: a copy whose extension was reloaded is dead and does not count.
const scope = globalThis as typeof globalThis & {
    __admitmeContent?: () => boolean;
};

function liveCopyListens(): boolean {
    try {
        return scope.__admitmeContent?.() === true;
    } catch {
        return false;
    }
}

if (!liveCopyListens()) {
    scope.__admitmeContent = () => {
        try {
            return Boolean(chrome.runtime?.id);
        } catch {
            return false;
        }
    };
    chrome.runtime.onMessage.addListener(onMessage);
}
