import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import {
    deleteEssays,
    deleteResponses,
    destroyAccount,
    exportMethod,
} from '@/actions/App/Http/Controllers/Settings/PrivacyController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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

export default function Privacy({
    legalVersion,
}: {
    legalVersion: string;
}) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const resetPasswordRef = useRef<HTMLInputElement>(null);
    const accountPasswordRef = useRef<HTMLInputElement>(null);
    const [resetOpen, setResetOpen] = useState(false);

    return (
        <>
            <Head title="Privacy & data" />

            <div className="space-y-10">
                <Heading
                    variant="small"
                    title="Privacy & data"
                    description={`Legal documents version ${legalVersion}.`}
                />

                {flash?.status === 'responses-deleted' ? (
                    <p className="text-sm text-emerald-600 dark:text-emerald-400">
                        Onboarding responses were deleted and knowledge base
                        cleared.
                    </p>
                ) : null}
                {flash?.status === 'essays-not-implemented' ? (
                    <p className="text-sm text-muted-foreground">
                        Essay drafts are not stored yet — nothing to delete.
                    </p>
                ) : null}

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Your data"
                        description="Export or remove what we store."
                    />
                    <Form
                        {...exportMethod.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                Download my data
                            </Button>
                        )}
                    </Form>
                </section>

                <section className="space-y-3">
                    <Heading
                        variant="small"
                        title="Legal"
                        description="Read how we handle your information."
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="link"
                            className="h-auto p-0 text-primary"
                            asChild
                        >
                            <Link href="/legal/privacy">Privacy policy</Link>
                        </Button>
                        <Button
                            variant="link"
                            className="h-auto p-0 text-primary"
                            asChild
                        >
                            <Link href="/legal/terms">Terms of service</Link>
                        </Button>
                    </div>
                </section>

                <section className="space-y-6 rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                    <Heading
                        variant="small"
                        title="Danger zone"
                        description="These actions cannot be undone."
                    />

                    <div className="flex flex-wrap gap-2">
                        <Form
                            {...deleteEssays.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Delete all essays
                                </Button>
                            )}
                        </Form>

                        <Dialog open={resetOpen} onOpenChange={setResetOpen}>
                            <DialogTrigger asChild>
                                <Button variant="outline" size="sm">
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
                    </div>

                    <div className="space-y-6 border-t border-destructive/20 pt-6">
                        <Heading
                            variant="small"
                            title="Delete account"
                            description="Delete your account and all of its resources"
                        />
                        <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                            <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                                <p className="font-medium">Warning</p>
                                <p className="text-sm">
                                    Please proceed with caution, this cannot be
                                    undone.
                                </p>
                            </div>

                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="destructive"
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
                                        resources and data will also be
                                        permanently deleted. Please enter your
                                        password to confirm you would like to
                                        permanently delete your account.
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
                                                        ref={
                                                            accountPasswordRef
                                                        }
                                                        type="password"
                                                        name="password"
                                                        placeholder="Password"
                                                        autoComplete="current-password"
                                                    />

                                                    <InputError
                                                        message={
                                                            errors.password
                                                        }
                                                    />
                                                </div>

                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button
                                                            type="button"
                                                            variant="secondary"
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
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}

Privacy.layout = {
    breadcrumbs: [{ title: 'Privacy', href: '/settings/privacy' }],
};
