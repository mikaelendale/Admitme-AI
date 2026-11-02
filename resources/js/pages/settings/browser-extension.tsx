import { Head, router } from '@inertiajs/react';
import {
    Browsers,
    CaretDown,
    Check,
    ClockCounterClockwise,
    Copy,
    LinkSimple,
    ListNumbers,
} from '@phosphor-icons/react';
import { useState } from 'react';
import { BrowserSessionCard } from '@/components/admitme/browser-session';
import type { BrowserSessionData } from '@/components/admitme/browser-session';
import {
    EmptyState,
    IconTile,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import { pillButton, settingsBreadcrumbs } from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useHydrated } from '@/hooks/use-hydrated';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';

type Device = {
    id: number;
    label: string;
    lastUsedAt: string | null;
    expiresAt: string;
    revoked: boolean;
};

type Props = {
    devices: Device[];
    sessions: BrowserSessionData[];
    serverUrl: string;
};

function when(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : 'never';
}

export default function BrowserExtensionSettings({
    devices,
    sessions,
    serverUrl,
}: Props) {
    const [code, setCode] = useState<{ code: string; minutes: number } | null>(
        null,
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, copy] = useClipboard();
    // Expiry and local times need the browser clock, so they appear once the page has hydrated.
    const hydrated = useHydrated();
    const isExpired = (device: Device) =>
        hydrated && new Date(device.expiresAt) <= new Date();
    const usable = devices.filter(
        (device) => !device.revoked && !isExpired(device),
    );

    async function createCode() {
        setBusy(true);
        setError(null);

        try {
            const res = await fetch('/settings/browser-extension/codes', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
            });
            const data = (await res.json().catch(() => ({}))) as {
                code?: string;
                expires_in_minutes?: number;
                message?: string;
            };

            if (!res.ok || !data.code) {
                setError(
                    data.message ??
                        'Could not create a code. Try again in a minute.',
                );

                return;
            }

            setCode({
                code: data.code,
                minutes: data.expires_in_minutes ?? 10,
            });
        } catch {
            setError('Could not create a code. Check your connection.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title="Browser extension" />

            <p className="-mt-3 mb-8 text-[14px] leading-relaxed text-muted-foreground">
                Give the mentor one tab in your browser and it fills your
                college portals for you: it opens the portal, reads each page
                and fills in what it knows from your profile. You log in
                yourself, and nothing final (submitting, paying, signing)
                happens until you press Allow.
            </p>

            <Section title="Link a browser" icon={LinkSimple}>
                <div className="rounded-2xl border border-border bg-card p-5">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <p className="text-[14px] font-medium text-foreground">
                                One-time link code
                            </p>
                            <p className="mt-0.5 text-[13px] leading-snug text-muted-foreground">
                                Enter it in the extension's side panel with the
                                address{' '}
                                <span className="font-medium break-words text-foreground">
                                    {serverUrl}
                                </span>
                                .
                            </p>
                        </div>
                        <Button
                            className={`${pillButton} shrink-0 self-start sm:self-auto`}
                            onClick={() => void createCode()}
                            disabled={busy}
                        >
                            Create a link code
                        </Button>
                    </div>

                    {code ? (
                        <div
                            className="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl bg-muted px-4 py-3"
                            data-testid="link-code"
                        >
                            <span className="font-mono text-[18px] font-semibold tracking-[0.12em] text-foreground">
                                {code.code}
                            </span>
                            <button
                                type="button"
                                onClick={() => void copy(code.code)}
                                aria-label={
                                    copied === code.code
                                        ? 'Code copied'
                                        : 'Copy code'
                                }
                                className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                {copied === code.code ? (
                                    <Check
                                        className="size-4 text-success"
                                        weight="bold"
                                    />
                                ) : (
                                    <Copy className="size-4" />
                                )}
                            </button>
                            <span className="text-[13px] text-muted-foreground">
                                Works once, for {code.minutes} minutes.
                            </span>
                        </div>
                    ) : null}

                    {error ? (
                        <p
                            role="alert"
                            className="mt-3 text-[13px] text-destructive"
                        >
                            {error}
                        </p>
                    ) : null}
                </div>
            </Section>

            <Section
                title="Linked browsers"
                icon={Browsers}
                description={
                    usable.length > 0
                        ? 'Revoking signs the extension out at once and ends its sessions.'
                        : undefined
                }
            >
                {devices.length === 0 ? (
                    <EmptyState
                        icon={
                            <IconTile
                                icon={Browsers}
                                tone="blue"
                                variant="glyph"
                            />
                        }
                        title="No browser is linked."
                        description="Create a link code above and enter it in the extension."
                    />
                ) : (
                    <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                        {devices.map((device) => {
                            const expired = isExpired(device);

                            return (
                                <li
                                    key={device.id}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4"
                                    data-device={device.id}
                                >
                                    <IconTile
                                        icon={Browsers}
                                        tone={
                                            device.revoked || expired
                                                ? 'slate'
                                                : 'blue'
                                        }
                                        variant="glyph"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[15px] font-medium text-foreground">
                                            {device.label}
                                        </p>
                                        <p className="mt-0.5 text-[13px] leading-snug text-muted-foreground">
                                            {device.revoked ? (
                                                <StatusBadge tone="danger">
                                                    Revoked
                                                </StatusBadge>
                                            ) : expired ? (
                                                <StatusBadge>
                                                    Link expired
                                                </StatusBadge>
                                            ) : hydrated ? (
                                                `Last used ${when(device.lastUsedAt)} · link expires ${new Date(device.expiresAt).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`
                                            ) : (
                                                'Linked'
                                            )}
                                        </p>
                                    </div>
                                    {!device.revoked && !expired ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="rounded-full px-3.5"
                                            onClick={() =>
                                                router.delete(
                                                    `/settings/browser-extension/devices/${device.id}`,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Revoke
                                        </Button>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Section>

            <Section title="Recent sessions" icon={ClockCounterClockwise}>
                {sessions.length === 0 ? (
                    <p className="text-[14px] text-muted-foreground">
                        No sessions yet.
                    </p>
                ) : (
                    <div className="space-y-3">
                        {sessions.map((session) => (
                            <BrowserSessionCard
                                key={session.id}
                                session={session}
                            />
                        ))}
                    </div>
                )}
            </Section>

            <Section title="How it works" icon={ListNumbers}>
                <details className="group rounded-2xl border border-border bg-card">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-5 py-4 text-[14px] font-medium text-foreground marker:hidden focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                        Install, link and let the mentor fill a portal
                        <CaretDown
                            className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                            aria-hidden
                        />
                    </summary>
                    <div className="border-t border-border px-5 py-4 text-[14px] leading-relaxed">
                        <ol className="list-decimal space-y-2 pl-5 text-muted-foreground marker:text-muted-foreground/70">
                            <li>
                                Install the extension. It is not in the Chrome
                                Web Store yet: build it with{' '}
                                <code className="rounded bg-muted px-1 text-[13px] text-foreground">
                                    npm run build:extension
                                </code>
                                , open chrome://extensions, turn on Developer
                                mode and choose “Load unpacked” → the{' '}
                                <code className="rounded bg-muted px-1 text-[13px] text-foreground">
                                    browser-extension/dist
                                </code>{' '}
                                folder.
                            </li>
                            <li>
                                Create a link code above and enter it in the
                                extension's side panel with the address{' '}
                                {serverUrl}.
                            </li>
                            <li>
                                In the side panel press “Let the mentor use this
                                browser”. Keep the panel open while the mentor
                                works: it shows every step and has a Stop
                                button.
                            </li>
                            <li>
                                Ask the mentor in Agent mode, for example “Fill
                                in my application on the portal”. When the
                                portal needs you to log in, it asks: log in in
                                that tab and tell it you’re in.
                            </li>
                            <li>
                                Before anything final (submitting, paying,
                                signing) the mentor asks you in the chat, and
                                the panel asks you to Allow it.
                            </li>
                        </ol>
                        <p className="mt-4 text-[13px] text-muted-foreground">
                            Passwords are never read or typed, file uploads stay
                            with you, and the mentor only uses the tab you gave
                            it. To approve each value instead, choose an
                            application in the panel and press “Use this tab”.
                        </p>
                    </div>
                </details>
            </Section>
        </>
    );
}

BrowserExtensionSettings.layout = {
    breadcrumbs: settingsBreadcrumbs(
        'Browser extension',
        '/settings/browser-extension',
    ),
};
