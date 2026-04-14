import type { ReactNode } from 'react';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import type { FeatureQuotasProps } from '@/types/feature-quotas';
import { edit as billingEdit } from '@/routes/billing';

type Prefs = {
    deadline_reminders: boolean;
    weekly_progress: boolean;
    profile_updates: boolean;
    essay_feedback: boolean;
    marketing: boolean;
};

export default function Notifications({ preferences }: { preferences: Prefs }) {
    const { flash, feature_quotas } = usePage<{
        flash?: { status?: string };
        feature_quotas?: FeatureQuotasProps | null;
    }>().props;

    const binary = feature_quotas?.binary ?? {};
    const deadlineOk = binary.deadline_reminders !== false;
    const weeklyOk = binary.weekly_progress_email !== false;

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
                                disabled={!deadlineOk}
                                helper={
                                    !deadlineOk ? (
                                        <span>
                                            Included on Sprint &amp; Pro.{' '}
                                            <Link
                                                href={billingEdit.url()}
                                                className="font-medium text-primary underline underline-offset-2"
                                            >
                                                Upgrade
                                            </Link>
                                        </span>
                                    ) : null
                                }
                            />
                            <ToggleRow
                                name="weekly_progress"
                                defaultChecked={preferences.weekly_progress}
                                label="Weekly progress summary"
                                disabled={!weeklyOk}
                                helper={
                                    !weeklyOk ? (
                                        <span>
                                            Included on Sprint &amp; Pro.{' '}
                                            <Link
                                                href={billingEdit.url()}
                                                className="font-medium text-primary underline underline-offset-2"
                                            >
                                                Upgrade
                                            </Link>
                                        </span>
                                    ) : null
                                }
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
    disabled = false,
    helper,
}: {
    name: string;
    label: string;
    defaultChecked: boolean;
    disabled?: boolean;
    helper?: ReactNode;
}) {
    return (
        <div
            className={`rounded-lg border border-border/60 bg-background/50 px-3 py-3 ${disabled ? 'opacity-70' : ''}`}
        >
            <div className="flex items-center gap-3">
                <input type="hidden" name={name} value="0" />
                <Input
                    id={name}
                    name={name}
                    type="checkbox"
                    value="1"
                    defaultChecked={defaultChecked}
                    disabled={disabled}
                    className="size-4 rounded border-input accent-primary disabled:cursor-not-allowed"
                />
                <Label
                    htmlFor={name}
                    className={`text-sm font-medium ${disabled ? 'cursor-not-allowed' : 'cursor-pointer'}`}
                >
                    {label}
                </Label>
            </div>
            {helper ? <p className="mt-2 pl-7 text-xs text-muted-foreground">{helper}</p> : null}
        </div>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Email alerts', href: '/settings/notifications' }],
};
