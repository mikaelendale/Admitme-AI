import { Form, Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';

type Prefs = {
    deadline_reminders: boolean;
    weekly_progress: boolean;
    profile_updates: boolean;
    essay_feedback: boolean;
    marketing: boolean;
};

export default function Notifications({ preferences }: { preferences: Prefs }) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    return (
        <>
            <Head title="Email alerts" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Email alerts"
                    description="Choose what we email you about."
                />

                {flash?.status === 'notifications-updated' ? (
                    <p className="text-sm text-emerald-600 dark:text-emerald-400">
                        Preferences saved.
                    </p>
                ) : null}

                <Form
                    action="/settings/notifications"
                    method="patch"
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing }) => (
                        <>
                            <ToggleRow
                                name="deadline_reminders"
                                defaultChecked={preferences.deadline_reminders}
                                label="Deadline reminders"
                            />
                            <ToggleRow
                                name="weekly_progress"
                                defaultChecked={preferences.weekly_progress}
                                label="Weekly progress summary"
                            />
                            <ToggleRow
                                name="profile_updates"
                                defaultChecked={preferences.profile_updates}
                                label="Profile updates"
                            />
                            <ToggleRow
                                name="essay_feedback"
                                defaultChecked={preferences.essay_feedback}
                                label="Essay feedback available"
                            />
                            <ToggleRow
                                name="marketing"
                                defaultChecked={preferences.marketing}
                                label="Marketing emails"
                            />
                            <Button type="submit" disabled={processing}>
                                Save notifications
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function ToggleRow({
    name,
    label,
    defaultChecked,
}: {
    name: string;
    label: string;
    defaultChecked: boolean;
}) {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-border/60 bg-background/50 px-3 py-3">
            <input type="hidden" name={name} value="0" />
            <Input
                id={name}
                name={name}
                type="checkbox"
                value="1"
                defaultChecked={defaultChecked}
                className="size-4 rounded border-input accent-primary"
            />
            <Label htmlFor={name} className="cursor-pointer text-sm font-medium">
                {label}
            </Label>
        </div>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Email alerts', href: '/settings/notifications' }],
};
