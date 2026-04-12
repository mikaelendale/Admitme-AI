import { Form, Head, Link, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectItem, SelectContent, SelectValue, SelectTrigger } from '@/components/ui/select';

type Prefs = {
    mentor_tone: string;
    essay_feedback_style: string;
};

export default function AiSettings({
    preferences,
    aiContextLastUpdated,
}: {
    preferences: Prefs;
    aiContextLastUpdated: string | null;
}) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    return (
        <>
            <Head title="AI preferences" />

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="AI preferences"
                    description="Tune how AdmitMe mentors and essay feedback sound."
                />

                {flash?.status === 'ai-preferences-updated' ? (
                    <p className="text-sm text-emerald-600 dark:text-emerald-400">
                        Preferences saved.
                    </p>
                ) : null}
                {flash?.status === 'ai-context-regeneration-queued' ? (
                    <p className="text-sm text-emerald-600 dark:text-emerald-400">
                        Profile knowledge base refresh queued — check back in a
                        minute.
                    </p>
                ) : null}

                <Form
                    action="/settings/ai"
                    method="patch"
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="mentor_tone">
                                    Mentor tone
                                </Label>
                                <Select
                                    defaultValue={preferences.mentor_tone}
                                    name="mentor_tone"
                                >
                                    <SelectTrigger
                                        id="mentor_tone"
                                        className="flex h-10 w-full max-w-md"
                                    >
                                        <SelectValue placeholder="Select mentor tone" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="brutally_honest">
                                            Brutally honest
                                        </SelectItem>
                                        <SelectItem value="supportive">
                                            Supportive
                                        </SelectItem>
                                        <SelectItem value="balanced">
                                            Balanced
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="essay_feedback_style">
                                    Essay feedback
                                </Label>
                                <Select
                                    defaultValue={preferences.essay_feedback_style}
                                    name="essay_feedback_style"
                                >
                                    <SelectTrigger
                                        id="essay_feedback_style"
                                        className="flex h-10 w-full max-w-md"
                                    >
                                        <SelectValue placeholder="Select feedback style" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="detailed_constructive">
                                            Detailed & constructive
                                        </SelectItem>
                                        <SelectItem value="concise">
                                            Concise
                                        </SelectItem>
                                        <SelectItem value="line_by_line">
                                            Line by line
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button type="submit" disabled={processing}>
                                Save AI preferences
                            </Button>
                        </>
                    )}
                </Form>

                <div className="space-y-3 rounded-lg border border-border/60 bg-muted/20 p-4">
                    <Heading
                        variant="small"
                        title="Knowledge base"
                        description="Compact profile summary used for chat and tools (refreshed after onboarding and when you regenerate)."
                    />
                    <p className="text-sm text-muted-foreground">
                        The structured data behind this summary comes from your{' '}
                        <strong className="font-medium text-foreground">
                            Profile overview
                        </strong>{' '}
                        wizard (brain dump, goals, review). Edit that profile to change
                        what the AI knows — then regenerate here to refresh the summary.
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="secondary" size="sm">
                            <Link href="/profile-overview?wizard=1">
                                Edit profile knowledge
                            </Link>
                        </Button>
                    </div>
                    {aiContextLastUpdated ? (
                        <p className="text-xs text-muted-foreground">
                            Last updated: {aiContextLastUpdated}
                        </p>
                    ) : (
                        <p className="text-xs text-muted-foreground">
                            Not built yet. Complete onboarding or regenerate.
                        </p>
                    )}
                    <Form
                        action="/settings/ai/regenerate-context"
                        method="post"
                        options={{ preserveScroll: true }}
                    >
                        <Button type="submit" variant="outline" size="sm">
                            Regenerate profile summary
                        </Button>
                    </Form>
                </div>
            </div>
        </>
    );
}

AiSettings.layout = {
    breadcrumbs: [{ title: 'AI', href: '/settings/ai' }],
};
