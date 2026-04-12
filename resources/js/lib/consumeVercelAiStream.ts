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

export function streamEventLabel(part: VercelAiStreamPart): string | null {
    switch (part.type) {
        case 'start':
            return 'Stream started';
        case 'text-start':
            return 'Generating…';
        case 'text-delta':
            return null;
        case 'text-end':
            return null;
        case 'finish':
            return 'Complete';
        case 'tool-input-available': {
            const toolLabels: Record<string, string> = {
                'GetStudentAdmissionContextTool': 'Reading your profile…',
                'GetStudentCollegeList': 'Loading your college list…',
                'StartEssayRunTool': 'Starting essay generation…',
                'StartGapFixTool': 'Running gap analysis…',
                'StartEssayRoastTool': 'Roasting your essay…',
                'StartWhyThisSchoolTool': 'Writing Why This School draft…',
                'StartCollegeListCouncilJobTool': 'Starting college list council…',
                'RunCollegeListAgentTool': 'Building your college list…',
                'UpsertStudentCollegeList': 'Updating your list…',
                'GetCollegeListRunArtifactsTool': 'Loading council results…',
            };
            return toolLabels[String(part.toolName ?? '')] ?? `Thinking (${String(part.toolName ?? 'tool')})…`;
        }
        case 'tool-output-available':
            return 'Tool finished';
        case 'data-conversation-id':
            return 'Conversation linked';
        default:
            return typeof part.type === 'string' ? part.type : null;
    }
}
