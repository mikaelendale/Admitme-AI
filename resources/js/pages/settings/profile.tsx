import { Form, Head, Link, usePage } from '@inertiajs/react';
import { IdentificationCard, SealCheck } from '@phosphor-icons/react';
import { update } from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import { Section, StatusBadge } from '@/components/page/kit';
import {
    fieldClass,
    FieldRow,
    FormFooter,
    pillButton,
    RowList,
    settingsBreadcrumbs,
    UnsavedChanges,
} from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

const FORM_ID = 'settings-profile-form';

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage().props;
    const unverified = mustVerifyEmail && auth.user.email_verified_at === null;

    return (
        <>
            <Head title="Profile settings" />

            <Section title="Account" icon={IdentificationCard}>
                <Form
                    {...update.form()}
                    id={FORM_ID}
                    options={{
                        preserveScroll: true,
                    }}
                    setDefaultsOnSuccess
                >
                    {({
                        processing,
                        recentlySuccessful,
                        errors,
                        isDirty,
                        reset,
                    }) => (
                        <>
                            <UnsavedChanges
                                dirty={isDirty}
                                formId={FORM_ID}
                                processing={processing}
                                onDiscard={() => reset()}
                            />

                            <RowList>
                                <FieldRow label="Name" htmlFor="name">
                                    <Input
                                        id="name"
                                        className={fieldClass}
                                        defaultValue={auth.user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                        placeholder="Full name"
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.name}
                                    />
                                </FieldRow>

                                <FieldRow
                                    label="Email address"
                                    htmlFor="email"
                                    hint={
                                        auth.user.email_verified_at ? (
                                            <StatusBadge
                                                tone="success"
                                                icon={SealCheck}
                                                className="mt-1.5"
                                            >
                                                Verified
                                            </StatusBadge>
                                        ) : null
                                    }
                                >
                                    <Input
                                        id="email"
                                        type="email"
                                        className={fieldClass}
                                        defaultValue={auth.user.email}
                                        name="email"
                                        required
                                        autoComplete="username"
                                        placeholder="Email address"
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.email}
                                    />

                                    {unverified ? (
                                        <div className="mt-2.5 text-[13px] text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="font-medium text-foreground underline decoration-foreground/25 underline-offset-4 transition-colors hover:decoration-foreground"
                                            >
                                                Resend the verification email
                                            </Link>
                                            {status ===
                                            'verification-link-sent' ? (
                                                <p className="mt-1.5 font-medium text-emerald-700 dark:text-emerald-400">
                                                    A new verification link has
                                                    been sent to your email
                                                    address.
                                                </p>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </FieldRow>
                            </RowList>

                            <FormFooter saved={recentlySuccessful}>
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                    className={pillButton}
                                >
                                    Save changes
                                </Button>
                            </FormFooter>
                        </>
                    )}
                </Form>
            </Section>
        </>
    );
}

Profile.layout = {
    breadcrumbs: settingsBreadcrumbs('Profile', edit.url()),
};
