/** Mirrors App\Domain\Discovery\CollegeAssessor output. */
export type ReasonStatus = 'good' | 'concern' | 'unknown' | 'info';

export type AssessmentReason = {
    kind: string;
    status: ReasonStatus;
    text: string;
    basis: 'profile' | 'source' | 'estimate' | 'missing';
    basisLabel: string;
    source: {
        url: string;
        title: string | null;
        type: string;
        private: boolean;
        checkedAt: string | null;
    } | null;
    quote: string | null;
};

export type AffordabilityStatus =
    'within_budget' | 'needs_aid' | 'over_budget' | 'incomplete';

export type Affordability = {
    status: AffordabilityStatus;
    complete: boolean;
    summary: string;
    missing: string[];
    budget: { amount: number; currency: string } | null;
    cost: { amount: number; currency: string; totalCost: boolean } | null;
};

export type CollegeAssessment = {
    reasons: AssessmentReason[];
    affordability: Affordability;
    counts: { good: number; concern: number; unknown: number };
    requirements: {
        onFile: number;
        conflicts: number;
        missingKeyTypes: string[];
    };
    cycle: string | null;
    country: string | null;
};

export const AFFORDABILITY_LABELS: Record<AffordabilityStatus, string> = {
    within_budget: 'Within budget',
    needs_aid: 'Needs aid',
    over_budget: 'Over budget',
    incomplete: 'Affordability unknown',
};

export function affordabilityTone(status: AffordabilityStatus): string {
    switch (status) {
        case 'within_budget':
            return 'border-chart-2/40 bg-chart-2/10 text-chart-2';
        case 'needs_aid':
            return 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200';
        case 'over_budget':
            return 'border-destructive/40 bg-destructive/10 text-destructive';
        case 'incomplete':
            return 'border-border bg-muted/50 text-muted-foreground';
    }
}
