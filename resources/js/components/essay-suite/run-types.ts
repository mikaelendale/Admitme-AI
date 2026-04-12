export type EssaySchoolOption = {
    id: string;
    name: string;
    tier: string;
    fit_score?: number;
};

export type EssayRecentRunRow = {
    id: string;
    run_type: string;
    status: string;
    college_list_school_id: string;
    school_name: string;
    title_for_card: string | null;
    user_prompt_excerpt: string;
    created_at: string | null;
    has_output: boolean;
};
