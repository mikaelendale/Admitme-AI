/**
 * Versioned contract between the AdmitMe server, the side panel and the content script (Phase 10).
 * Commands are packaged handlers with validated JSON payloads — never model-generated JavaScript.
 */
export const COMMAND_CONTRACT_VERSION = 1;

export type CommandType =
    'observe_page' | 'fill_field' | 'select_option' | 'click_supported_control';

/** A visible form field as observed (password, hidden and file inputs are never reported). */
export type ObservedField = {
    field_id: string;
    label: string;
    name: string;
    type: string;
    value: string;
    options: string[];
    required: boolean;
};

export type Observation = {
    url: string;
    origin: string;
    fields: ObservedField[];
};

/** A leased command from GET /api/browser/sessions/{id}/commands. */
export type LeasedCommand = {
    id: string;
    type: CommandType;
    field_id: string;
    value: string;
    label: string;
    /** The previous lease ended without an acknowledgement: read the field first; act only if needed. */
    reconcile: boolean;
};

export type PerformResult = {
    outcome: 'done' | 'failed' | 'uncertain';
    observed_value: string | null;
    message?: string;
};

/** Messages from the side panel to the content script. */
export type ContentMessage =
    | { type: 'admitme.observe'; version: number }
    | { type: 'admitme.perform'; version: number; command: LeasedCommand };
