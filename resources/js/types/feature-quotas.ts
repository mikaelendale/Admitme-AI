/** Mirrors `FeatureQuotaManager::usageSummaryForInertia` + per-feature status payloads. */

export type QuotaFeatureDetail = {
    feature: string;
    label: string;
    limit: number | null;
    used: number;
    remaining: number | null;
    warning: boolean;
    resets_at?: string | null;
};

export type FeatureQuotasProps = {
    plan: 'trial' | 'sprint' | 'pro';
    plan_label: string;
    plan_bucket: string;
    window_days: number | null;
    resets_at: string | null;
    features: Record<
        string,
        {
            limit: number | null;
            used: number;
            remaining: number | null;
            warning: boolean;
            resets_at?: string | null;
            label: string;
        }
    >;
    binary: Record<string, boolean>;
    chat: {
        mode?: string;
        conversations_limit?: number;
        conversations_used?: number;
        conversations_remaining?: number | null;
        messages_per_convo_limit?: number;
        messages_limit?: number;
        messages_used?: number;
        messages_remaining?: number | null;
        warning?: boolean;
        resets_at?: string | null;
    };
};
