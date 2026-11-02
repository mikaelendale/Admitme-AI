import { Form, Head, Link, usePage } from '@inertiajs/react';
import { EnvelopeSimple } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { Section } from '@/components/page/kit';
import {
    FormFooter,
    Notice,
    pillButton,
    RowList,
    SettingRow,
    settingsBreadcrumbs,
    SwitchInput,
    UnsavedChanges,
} from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
import { edit as billingEdit } from '@/routes/billing';
import type { FeatureQuotasProps } from '@/types/feature-quotas';

type Prefs = {
    deadline_reminders: boolean;
    weekly_progress: boolean;
    profile_updates: boolean;
    essay_feedback: boolean;
    marketing: boolean;
};

const FORM_ID = 'email-alerts-form';

function UpgradeHint() {
    return (
        <span>
            Included on Sprint &amp; Pro.{' '}
            <Link
                href={billingEdit.url()}
                className="font-medium text-foreground underline decoration-foreground/25 underline-offset-4 hover:decoration-foreground"
            >
                Upgrade
            </Link>
        </span>
    );
}

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

            {flash?.status === 'notifications-updated' ? (
                <Notice tone="success" className="mb-8">
                    Preferences saved.
                </Notice>
            ) : null}

            <Section
                title="Email me about"
                icon={EnvelopeSimple}
                description="Choose what we email you about."
            >
                <Form
                    id={FORM_ID}
                    action="/settings/notifications"
                    method="patch"
                    options={{ preserveScroll: true }}
                    setDefaultsOnSuccess
                >
                    {({ processing, isDirty, reset, recentlySuccessful }) => (
                        <>
                            <UnsavedChanges
                                dirty={isDirty}
                                formId={FORM_ID}
                                processing={processing}
                                onDiscard={() => reset()}
                            />

                            <RowList>
                                <ToggleRow
                                    name="deadline_reminders"
                                    defaultChecked={
                                        preferences.deadline_reminders
                                    }
                                    label="Deadline reminders"
                                    disabled={!deadlineOk}
                                    helper={
                                        !deadlineOk ? <UpgradeHint /> : null
                                    }
                                />
                                <ToggleRow
                                    name="weekly_progress"
                                    defaultChecked={preferences.weekly_progress}
                                    label="Weekly progress summary"
                                    disabled={!weeklyOk}
                                    helper={!weeklyOk ? <UpgradeHint /> : null}
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
                            </RowList>

                            <FormFooter saved={recentlySuccessful}>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className={pillButton}
                                >
                                    Save changes
                                </Button>
                            </FormFooter>
                        </>
                    )}
                </Form>
            </Section>

            <p className="text-[13px] leading-relaxed text-muted-foreground">
                Emails about finished work (essays, college lists, gap analysis)
                and your pass or payments are set on{' '}
                <Link
                    href="/notifications"
                    className="font-medium text-foreground underline decoration-foreground/25 underline-offset-4 hover:decoration-foreground"
                >
                    Notifications
                </Link>
                .
            </p>
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
        <SettingRow
            inline
            htmlFor={name}
            title={label}
            description={helper ?? undefined}
            className={disabled ? 'opacity-80' : undefined}
        >
            <input type="hidden" name={name} value="0" />
            <SwitchInput
                id={name}
                name={name}
                value="1"
                defaultChecked={defaultChecked}
                disabled={disabled}
            />
        </SettingRow>
    );
}

Notifications.layout = {
    breadcrumbs: settingsBreadcrumbs('Email alerts', '/settings/notifications'),
};
