import { Form, Head, usePage } from '@inertiajs/react';
import {
    Archive,
    Database,
    FileText,
    Scales,
    ShieldCheck,
    Warning,
} from '@phosphor-icons/react';
import { useRef, useState } from 'react';
import {
    deleteEssays,
    deleteResponses,
    destroyAccount,
    exportMethod,
} from '@/actions/App/Http/Controllers/Settings/PrivacyController';
import InputError from '@/components/input-error';
import { IconTile, ListCard, ListRow, Section } from '@/components/page/kit';
import {
    fieldClass,
    Notice,
    pillButton,
    RowList,
    SettingRow,
    settingsBreadcrumbs,
} from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function Privacy({ legalVersion }: { legalVersion: string }) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const resetPasswordRef = useRef<HTMLInputElement>(null);
    const accountPasswordRef = useRef<HTMLInputElement>(null);
    const [resetOpen, setResetOpen] = useState(false);

    return (
        <>
            <Head title="Privacy & data" />

            {flash?.status === 'responses-deleted' ||
            flash?.status === 'essays-not-implemented' ? (
                <div className="mb-8 space-y-2">
                    {flash?.status === 'responses-deleted' ? (
                        <Notice tone="success">
                            Onboarding responses were deleted and knowledge base
                            cleared.
                        </Notice>
                    ) : null}
                    {flash?.status === 'essays-not-implemented' ? (
                        <Notice>
                            Essay drafts are not stored yet — nothing to delete.
                        </Notice>
                    ) : null}
                </div>
            ) : null}

            <Section
                title="Your data"
                icon={Database}
                description="Export or remove what we store."
            >
                <ListCard>
                    <ListRow
                        icon={<IconTile icon={FileText} tone="blue" />}
                        title="Account details"
                        description="As a JSON file."
                        trailing={
                            <Form
                                {...exportMethod.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        className="rounded-full px-3.5"
                                        disabled={processing}
                                    >
                                        Download JSON
                                    </Button>
                                )}
                            </Form>
                        }
                    />
                    <ListRow
                        href="/exports"
                        icon={<IconTile icon={Archive} tone="violet" />}
                        title="Full archive"
                        description="Profile, college list, applications and checklists, tasks, and your documents and writing."
                    />
                </ListCard>
            </Section>

            <Section
                title="Legal"
                icon={Scales}
                description={`Documents version ${legalVersion}.`}
            >
                <ListCard>
                    <ListRow
                        href="/legal/privacy"
                        icon={<IconTile icon={ShieldCheck} tone="green" />}
                        title="Privacy policy"
                        description="How we handle your information."
                    />
                    <ListRow
                        href="/legal/terms"
                        icon={<IconTile icon={FileText} tone="slate" />}
                        title="Terms of service"
                    />
                </ListCard>
            </Section>

            <Section
                title="Danger zone"
                icon={Warning}
                description="These actions cannot be undone."
            >
                <RowList>
                    <SettingRow
                        title="Essays"
                        description="Delete every saved essay."
                    >
                        <Form
                            {...deleteEssays.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className={pillButton}
                                    disabled={processing}
                                >
                                    Delete all essays
                                </Button>
                            )}
                        </Form>
                    </SettingRow>

                    <SettingRow
                        title="Onboarding responses"
                        description="Delete your saved onboarding answers and sessions, and clear your AI knowledge base."
                    >
                        <Dialog open={resetOpen} onOpenChange={setResetOpen}>
                            <DialogTrigger asChild>
                                <Button
                                    variant="outline"
                                    className={pillButton}
                                >
                                    Delete all responses
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Reset onboarding responses?
                                </DialogTitle>
                                <DialogDescription>
                                    This deletes saved onboarding answers and
                                    sessions and clears your AI knowledge base.
                                    Type RESET and your password to confirm.
                                </DialogDescription>
                                <Form
                                    {...deleteResponses.form()}
                                    options={{ preserveScroll: true }}
                                    onError={() =>
                                        resetPasswordRef.current?.focus()
                                    }
                                    onSuccess={() => setResetOpen(false)}
                                    className="space-y-4"
                                >
                                    {({
                                        processing,
                                        errors,
                                        resetAndClearErrors,
                                    }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="confirmation">
                                                    Type RESET
                                                </Label>
                                                <Input
                                                    id="confirmation"
                                                    name="confirmation"
                                                    autoComplete="off"
                                                    className={fieldClass}
                                                />
                                                <InputError
                                                    message={
                                                        errors.confirmation
                                                    }
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="reset-password">
                                                    Password
                                                </Label>
                                                <Input
                                                    id="reset-password"
                                                    ref={resetPasswordRef}
                                                    type="password"
                                                    name="password"
                                                    autoComplete="current-password"
                                                    className={fieldClass}
                                                />
                                                <InputError
                                                    message={errors.password}
                                                />
                                            </div>
                                            <DialogFooter className="gap-2">
                                                <DialogClose asChild>
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        className={pillButton}
                                                        onClick={() =>
                                                            resetAndClearErrors()
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                </DialogClose>
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    className={pillButton}
                                                    disabled={processing}
                                                >
                                                    Delete responses
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </SettingRow>

                    <SettingRow
                        title="Account"
                        description="Delete your account and all of its data, permanently."
                    >
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    className={pillButton}
                                    data-test="delete-user-button"
                                >
                                    Delete account
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete your
                                    account?
                                </DialogTitle>
                                <DialogDescription>
                                    Once your account is deleted, all of its
                                    resources and data will also be permanently
                                    deleted. This cannot be undone. Enter your
                                    password to confirm.
                                </DialogDescription>

                                <Form
                                    {...destroyAccount.form()}
                                    options={{
                                        preserveScroll: true,
                                    }}
                                    onError={() =>
                                        accountPasswordRef.current?.focus()
                                    }
                                    resetOnSuccess
                                    className="space-y-6"
                                >
                                    {({
                                        resetAndClearErrors,
                                        processing,
                                        errors,
                                    }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <Label
                                                    htmlFor="privacy-delete-password"
                                                    className="sr-only"
                                                >
                                                    Password
                                                </Label>

                                                <Input
                                                    id="privacy-delete-password"
                                                    ref={accountPasswordRef}
                                                    type="password"
                                                    name="password"
                                                    placeholder="Password"
                                                    autoComplete="current-password"
                                                    className={fieldClass}
                                                />

                                                <InputError
                                                    message={errors.password}
                                                />
                                            </div>

                                            <DialogFooter className="gap-2">
                                                <DialogClose asChild>
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        className={pillButton}
                                                        onClick={() =>
                                                            resetAndClearErrors()
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                </DialogClose>

                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    className={pillButton}
                                                    disabled={processing}
                                                    data-test="confirm-delete-user-button"
                                                >
                                                    Delete account
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </SettingRow>
                </RowList>
            </Section>
        </>
    );
}

Privacy.layout = {
    breadcrumbs: settingsBreadcrumbs('Privacy', '/settings/privacy'),
};
