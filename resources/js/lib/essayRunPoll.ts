export type EssayRunPollJson = {
    run_id: string;
    status: string;
    run_type: string;
    title_for_card: string | null;
    output_markdown: string | null;
    error_message: string | null;
    school_name?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
};

export async function fetchEssayRunJson(runId: string): Promise<EssayRunPollJson | null> {
    const res = await fetch(`/essay-suite/runs/${runId}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) {
        return null;
    }

    return (await res.json()) as EssayRunPollJson;
}
