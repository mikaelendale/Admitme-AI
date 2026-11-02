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

        throw new Error(detail || text || `Stream failed (${response.status})`);
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
                    console.warn(
                        '[consumeVercelAiStream] Skipped malformed SSE line',
                        {
                            preview: payload.slice(0, 200),
                            error:
                                parseErr instanceof Error
                                    ? parseErr.message
                                    : String(parseErr),
                        },
                    );
                }
            }
        }
    }
}

export function toolCallIdFromOutput(part: VercelAiStreamPart): string | null {
    // A failed or denied tool call also ends its step; otherwise its spinner never stops.
    if (
        part.type === 'tool-output-available' ||
        part.type === 'tool-output-error' ||
        part.type === 'tool-output-denied'
    ) {
        return String(part.toolCallId ?? '') || null;
    }

    return null;
}
