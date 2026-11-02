import { Head } from '@inertiajs/react';
import { ClockCounterClockwise, SealCheck } from '@phosphor-icons/react';
import { ProposalCard } from '@/components/admitme/proposal-card';
import type { ProposalData } from '@/components/admitme/proposal-card';
import {
    EmptyState,
    IconTile,
    Page,
    PageHeader,
    Section,
} from '@/components/page/kit';

type PageProps = {
    pending: ProposalData[];
    recent: ProposalData[];
};

export default function ApprovalsPage({ pending, recent }: PageProps) {
    return (
        <>
            <Head title="Approvals" />

            <Page width="narrow">
                <PageHeader
                    title="Approvals"
                    description="Changes your mentor proposed to your profile or college list. Nothing changes until you approve — you can approve only some items, edit a value first, or reject."
                />

                <Section
                    title={
                        pending.length > 0
                            ? `Waiting for you · ${pending.length}`
                            : 'Waiting for you'
                    }
                    icon={SealCheck}
                >
                    {pending.length === 0 ? (
                        <EmptyState
                            icon={
                                <IconTile
                                    icon={SealCheck}
                                    tone="green"
                                    variant="glyph"
                                    size="lg"
                                />
                            }
                            title="You’re all caught up"
                            description="Nothing is waiting for your approval. Changes your mentor proposes show up here."
                        />
                    ) : (
                        <ul className="space-y-4">
                            {pending.map((proposal) => (
                                <li key={`${proposal.id}:${proposal.revision}`}>
                                    <ProposalCard initial={proposal} />
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                {recent.length > 0 ? (
                    <Section
                        title="Recently decided"
                        icon={ClockCounterClockwise}
                    >
                        <ul className="space-y-3">
                            {recent.map((proposal) => (
                                <li key={proposal.id}>
                                    <ProposalCard initial={proposal} />
                                </li>
                            ))}
                        </ul>
                    </Section>
                ) : null}
            </Page>
        </>
    );
}
