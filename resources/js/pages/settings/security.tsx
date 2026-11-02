import { Form, Head } from '@inertiajs/react';
import { Password, ShieldCheck } from '@phosphor-icons/react';
import { useEffect, useRef, useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import { Section, StatusBadge } from '@/components/page/kit';
import PasswordInput from '@/components/password-input';
import {
    fieldClass,
    FieldRow,
    FormFooter,
    pillButton,
    RowList,
    SettingRow,
    settingsBreadcrumbs,
} from '@/components/settings/kit';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { edit } from '@/routes/security';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function Security({
    canManageTwoFactor = false,
    requiresConfirmation = false,
    twoFactorEnabled = false,
}: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    return (
        <>
            <Head title="Security settings" />

            <Section
                title="Password"
                icon={Password}
                description="Use a long, random password to keep your account safe."
            >
                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                >
                    {({ errors, processing, recentlySuccessful }) => (
                        <>
                            <RowList>
                                <FieldRow
                                    label="Current password"
                                    htmlFor="current_password"
                                >
                                    <PasswordInput
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        className={fieldClass}
                                        autoComplete="current-password"
                                        placeholder="Current password"
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.current_password}
                                    />
                                </FieldRow>

                                <FieldRow
                                    label="New password"
                                    htmlFor="password"
                                >
                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        className={fieldClass}
                                        autoComplete="new-password"
                                        placeholder="New password"
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.password}
                                    />
                                </FieldRow>

                                <FieldRow
                                    label="Confirm password"
                                    htmlFor="password_confirmation"
                                >
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        className={fieldClass}
                                        autoComplete="new-password"
                                        placeholder="Confirm password"
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.password_confirmation}
                                    />
                                </FieldRow>
                            </RowList>

                            <FormFooter saved={recentlySuccessful}>
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                    className={pillButton}
                                >
                                    Save password
                                </Button>
                            </FormFooter>
                        </>
                    )}
                </Form>
            </Section>

            {canManageTwoFactor && (
                <Section title="Two-factor authentication" icon={ShieldCheck}>
                    <RowList>
                        <SettingRow
                            title={
                                <span className="inline-flex items-center gap-2">
                                    Authenticator app
                                    <StatusBadge
                                        tone={
                                            twoFactorEnabled
                                                ? 'success'
                                                : 'neutral'
                                        }
                                    >
                                        {twoFactorEnabled ? 'On' : 'Off'}
                                    </StatusBadge>
                                </span>
                            }
                            description={
                                twoFactorEnabled
                                    ? 'At each sign-in you enter a random code from the authenticator (TOTP) app on your phone.'
                                    : 'Turn it on to be asked for a code from an authenticator (TOTP) app on your phone at each sign-in.'
                            }
                        >
                            {twoFactorEnabled ? (
                                <Form {...disable.form()}>
                                    {({ processing }) => (
                                        <Button
                                            variant="destructive"
                                            type="submit"
                                            disabled={processing}
                                            className={pillButton}
                                        >
                                            Disable 2FA
                                        </Button>
                                    )}
                                </Form>
                            ) : hasSetupData ? (
                                <Button
                                    onClick={() => setShowSetupModal(true)}
                                    className={pillButton}
                                >
                                    <ShieldCheck className="size-4" />
                                    Continue setup
                                </Button>
                            ) : (
                                <Form
                                    {...enable.form()}
                                    onSuccess={() => setShowSetupModal(true)}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className={pillButton}
                                        >
                                            Enable 2FA
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </SettingRow>
                    </RowList>

                    {twoFactorEnabled ? (
                        <div className="mt-4">
                            <TwoFactorRecoveryCodes
                                recoveryCodesList={recoveryCodesList}
                                fetchRecoveryCodes={fetchRecoveryCodes}
                                errors={errors}
                            />
                        </div>
                    ) : null}

                    <TwoFactorSetupModal
                        isOpen={showSetupModal}
                        onClose={() => setShowSetupModal(false)}
                        requiresConfirmation={requiresConfirmation}
                        twoFactorEnabled={twoFactorEnabled}
                        qrCodeSvg={qrCodeSvg}
                        manualSetupKey={manualSetupKey}
                        clearSetupData={clearSetupData}
                        fetchSetupData={fetchSetupData}
                        errors={errors}
                    />
                </Section>
            )}
        </>
    );
}

Security.layout = {
    breadcrumbs: settingsBreadcrumbs('Security', edit.url()),
};
