/** Badge styles for document version statuses (see App\Domain\Documents\DocumentVersionStatus). */
export const DOCUMENT_STATUS_STYLES: Record<string, string> = {
    queued: 'border-border/60 text-muted-foreground',
    processing: 'border-primary/40 text-primary',
    needs_review: 'border-amber-500/40 text-amber-700 dark:text-amber-300',
    ready: 'border-chart-2/40 text-chart-2',
    failed: 'border-destructive/40 text-destructive',
    superseded: 'border-border/60 text-muted-foreground',
};
