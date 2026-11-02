/** The side panel's AdmitMe API client and the tab it works with, shared by its views. */

export type Link = {
    server: string;
    token: string;
    student: string;
    device: string;
};

export class ApiError extends Error {
    readonly status: number;

    constructor(status: number, message: string) {
        super(message);
        this.status = status;
    }
}

function messageOf(data: unknown, status: number): string {
    const body = (data ?? {}) as {
        message?: unknown;
        errors?: Record<string, unknown>;
    };
    const first = body.errors
        ? Object.values(body.errors).flat()[0]
        : undefined;

    if (typeof first === 'string') {
        return first;
    }

    return typeof body.message === 'string' && body.message !== ''
        ? body.message
        : `AdmitMe answered with an error (${status}).`;
}

export type ApiOptions = {
    method?: string;
    body?: unknown;
    okStatuses?: number[];
};

export async function api<T>(
    server: string,
    token: string | null,
    path: string,
    options: ApiOptions = {},
): Promise<T> {
    return (await apiStatus<T>(server, token, path, options)).data;
}

/** Like api(), with the HTTP status (for answers such as 409 / 410 that are listed in okStatuses). */
export async function apiStatus<T>(
    server: string,
    token: string | null,
    path: string,
    options: ApiOptions = {},
): Promise<{ status: number; data: T }> {
    let res: Response;

    try {
        res = await fetch(server + path, {
            method: options.method ?? 'GET',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body:
                options.body === undefined
                    ? undefined
                    : JSON.stringify(options.body),
        });
    } catch {
        throw new ApiError(
            0,
            'Could not reach AdmitMe. Check your connection and the AdmitMe address.',
        );
    }

    const data: unknown = await res.json().catch(() => null);

    if (!res.ok && !(options.okStatuses ?? []).includes(res.status)) {
        throw new ApiError(res.status, messageOf(data, res.status));
    }

    return { status: res.status, data: data as T };
}

/** For automated tests the panel can be opened as a page bound to one tab: sidepanel/index.html?tab=123. */
export const PINNED_TAB = (() => {
    const value = new URLSearchParams(location.search).get('tab');

    return value !== null && /^\d+$/.test(value) ? Number(value) : null;
})();

export async function currentTab(): Promise<chrome.tabs.Tab> {
    if (PINNED_TAB !== null) {
        return chrome.tabs.get(PINNED_TAB);
    }

    const [tab] = await chrome.tabs.query({
        active: true,
        currentWindow: true,
    });

    if (!tab) {
        throw new Error('Open the application form in a tab first.');
    }

    return tab;
}

export function originOf(url: string | undefined): string | null {
    try {
        const parsed = new URL(url ?? '');

        return parsed.protocol === 'http:' || parsed.protocol === 'https:'
            ? parsed.origin
            : null;
    } catch {
        return null;
    }
}
