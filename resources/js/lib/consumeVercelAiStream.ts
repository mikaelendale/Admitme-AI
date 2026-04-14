/**
 * Parse Laravel AI Vercel AI UI message stream (see ai-sdk.md / StreamableAgentResponse::usingVercelDataProtocol).
 */

export type VercelAiStreamPart = Record<string, unknown> & {
    type?: string;
    delta?: string;
    /** Tool result body from `tool-output-available` events */
    output?: unknown;
    data?: { conversationId?: string };
};

export async function consumeVercelAiStream(
    response: Response,
    onTextDelta: (chunk: string) => void,
    onEvent?: (part: VercelAiStreamPart) => void,
): Promise<void> {
    if (!response.ok) {
        const text = await response.text();

        let detail = text.slice(0, 2000);
        try {
            const parsed = JSON.parse(text) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            if (parsed.message) {
                detail = parsed.message;
            } else if (parsed.errors) {
                detail = JSON.stringify(parsed.errors);
            }
        } catch {
            // keep HTML or plain text preview
        }

        console.error(
            `[consumeVercelAiStream] HTTP ${response.status} ${response.statusText}`,
            detail,
        );

        throw new Error(
            detail || text || `Stream failed (${response.status})`,
        );
    }

    const reader = response.body?.getReader();

    if (!reader) {
        throw new Error('No response body');
    }

    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });
        const parts = buffer.split('\n\n');
        buffer = parts.pop() ?? '';

        for (const part of parts) {
            for (const line of part.split('\n')) {
                if (!line.startsWith('data: ')) {
                    continue;
                }

                const payload = line.slice(6).trim();

                if (payload === '' || payload === '[DONE]') {
                    continue;
                }

                try {
                    const json = JSON.parse(payload) as VercelAiStreamPart;
                    onEvent?.(json);

                    if (
                        json.type === 'text-delta' &&
                        typeof json.delta === 'string'
                    ) {
                        onTextDelta(json.delta);
                    }
                } catch (parseErr) {
                    console.warn('[consumeVercelAiStream] Skipped malformed SSE line', {
                        preview: payload.slice(0, 200),
                        error:
                            parseErr instanceof Error
                                ? parseErr.message
                                : String(parseErr),
                    });
                }
            }
        }
    }
}

const TOOL_LABELS: Record<string, string> = {
    'GetStudentAdmissionContextTool': 'Reading your profile',
    'GetStudentCollegeList': 'Loading your college list',
    'StartEssayRunTool': 'Starting essay',
    'StartGapFixTool': 'Analyzing gaps',
    'StartEssayRoastTool': 'Roasting your essay',
    'StartWhyThisSchoolTool': 'Drafting Why This School',
    'StartCollegeListCouncilJobTool': 'Building your list',
    'RunCollegeListAgentTool': 'Building your list',
    'UpsertStudentCollegeList': 'Updating your list',
    'GetCollegeListRunArtifactsTool': 'Checking results',
    'WebSearch': 'Searching the web',
    'web_search': 'Searching the web',
};

const TOOL_ICONS: Record<string, string> = {
    'GetStudentAdmissionContextTool': 'profile',
    'GetStudentCollegeList': 'list',
    'StartEssayRunTool': 'essay',
    'StartGapFixTool': 'gap',
    'StartEssayRoastTool': 'roast',
    'StartWhyThisSchoolTool': 'essay',
    'StartCollegeListCouncilJobTool': 'council',
    'RunCollegeListAgentTool': 'council',
    'UpsertStudentCollegeList': 'list',
    'GetCollegeListRunArtifactsTool': 'list',
    'WebSearch': 'search',
    'web_search': 'search',
};

export type StreamToolStep = {
    id: string;
    label: string;
    icon: string;
    status: 'running' | 'done';
    toolName?: string;
};

export function streamEventLabel(part: VercelAiStreamPart): string | null {
    switch (part.type) {
        case 'start':
        case 'text-start':
        case 'text-delta':
        case 'text-end':
        case 'data-conversation-id':
            return null;
        case 'finish':
            return null;
        case 'tool-input-available': {
            return TOOL_LABELS[String(part.toolName ?? '')] ?? 'Working on it';
        }
        case 'tool-output-available':
            return null;
        default:
            return null;
    }
}

export function streamEventToStep(part: VercelAiStreamPart): StreamToolStep | null {
    if (part.type === 'tool-input-available') {
        const name = String(part.toolName ?? '');
        return {
            id: String(part.toolCallId ?? crypto.randomUUID()),
            label: TOOL_LABELS[name] ?? 'Working on it',
            icon: TOOL_ICONS[name] ?? 'default',
            status: 'running',
            toolName: name,
        };
    }
    return null;
}

export function toolCallIdFromOutput(part: VercelAiStreamPart): string | null {
    if (part.type === 'tool-output-available') {
        return String(part.toolCallId ?? '') || null;
    }
    return null;
}
