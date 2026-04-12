export type EssayScoreEntry = {
    score: number;
    reasoning: string;
};

export type EssayRoastScores = {
    authenticity: EssayScoreEntry;
    specificity: EssayScoreEntry;
    emotional_impact: EssayScoreEntry;
    structure: EssayScoreEntry;
    differentiation: EssayScoreEntry;
};

export type EssayRoastCliche = {
    phrase: string;
    reason: string;
    suggestion: string;
};

export type EssayRoastPayload = {
    overall_verdict: string;
    scores: EssayRoastScores;
    cliches: EssayRoastCliche[];
    structural_issues: string[];
    voice_issues: string[];
    strengths: string[];
    top_priority_fix: string;
};

export type EssayScoreOnlyPayload = {
    scores: EssayRoastScores;
    top_priority_fix: string;
};

export type WhyThisSchoolPayload = {
    draft: string;
    hooks_used: string[];
    generic_phrases_avoided: string[];
    suggested_improvements: string[];
};

export type WhySchoolDraftRow = {
    id: string;
    college_list_school_id: string;
    school_name: string;
    created_at: string | null;
    draft_preview: string;
};

export type SupplementalEssayRow = {
    id: string;
    group_key: string;
    school_display_name: string;
    school_name: string;
    college_list_school_id: string | null;
    prompt_text: string;
    word_limit: number | null;
    essay_type: string;
    status: string;
    content: string | null;
    notes: string | null;
    due_date: string | null;
    created_at: string | null;
    updated_at: string | null;
};
