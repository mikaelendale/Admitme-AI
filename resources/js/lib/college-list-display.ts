import type { CollegeAssessment } from '@/lib/college-assessment';

export type FitTier = 'reach' | 'match' | 'safety';

export type ApplicationStatus =
    | 'not_started'
    | 'researching'
    | 'applying'
    | 'submitted'
    | 'accepted'
    | 'rejected'
    | 'waitlisted';

export interface CollegeRow {
    id: string;
    name: string;
    location: string;
    tier: FitTier;
    fitScore: number;
    deadline: string;
    deadlinesDisplay: string;
    aidEstimate: string;
    netCostEstimate: string;
    whyFit: string;
    deadlineSort: number;
    applicationPortalUrl: string;
    admissionsCycleLabel: string;
    applicationStatus: ApplicationStatus;
    studentNotes: string;
    choiceStatus: CollegeChoiceStatus;
    /** Revision the screen was rendered from; sent back so stale edits are rejected. */
    revision: number;
    applicationId: string | null;
    /** Research run that suggested it (the run is the task; its suggestions are the artifacts). */
    runId?: string | null;
    notablePrograms?: string[];
    /** Fit check against the student's profile and sources; null when not computed. */
    assessment?: CollegeAssessment | null;
}

export type CollegeChoiceStatus =
    'suggested' | 'shortlisted' | 'applying' | 'archived';

export function choiceStatusLabel(status: CollegeChoiceStatus): string {
    switch (status) {
        case 'suggested':
            return 'Suggested';
        case 'shortlisted':
            return 'Shortlisted';
        case 'applying':
            return 'Applying';
        case 'archived':
            return 'Archived';
    }
}

export function tierLabel(tier: FitTier): string {
    switch (tier) {
        case 'reach':
            return 'Reach';
        case 'match':
            return 'Match';
        case 'safety':
            return 'Safety';
    }
}

export function tierStyles(tier: FitTier): string {
    switch (tier) {
        case 'reach':
            return 'bg-chart-4/15 text-chart-4';
        case 'match':
            return 'bg-primary/10 text-primary';
        case 'safety':
            return 'bg-chart-2/15 text-chart-2';
    }
}

export function fitScoreStyles(score: number): string {
    if (score >= 85) {
        return 'text-chart-2';
    }

    if (score >= 70) {
        return 'text-primary';
    }

    return 'text-chart-4';
}
