'use client';

import { Head } from '@inertiajs/react';
import { ChatCircle, NotePencil } from '@phosphor-icons/react';
import { useMentor } from '@/components/mentor/mentor-context';
import { relativeTime } from '@/components/mentor/mentor-types';
import {
    EmptyState,
    IconTile,
    ListCard,
    ListRow,
    Page,
    PageHeader,
} from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';

type Row = { id: string; title: string; updated_at: string | null };

export default function DashboardChatsIndex({
    conversations,
}: {
    conversations: Row[];
}) {
    const mentor = useMentor();
    const hydrated = useHydrated();

    const open = (id: string) => {
        mentor.setOpen(true);
        void mentor.loadConversation(id);
    };

    const startNew = () => {
        mentor.newConversation();
        mentor.setOpen(true);
    };

    return (
        <>
            <Head title="Chat history" />
            <Page width="narrow">
                <PageHeader
                    title="Chat history"
                    description="Every conversation with your mentor. Open one to continue it in the mentor panel."
                    actions={
                        <Button
                            onClick={startNew}
                            className="h-9 gap-2 rounded-full px-4"
                        >
                            <NotePencil className="size-4" />
                            New chat
                        </Button>
                    }
                />

                {conversations.length === 0 ? (
                    <EmptyState
                        icon={
                            <IconTile
                                icon={ChatCircle}
                                tone="sky"
                                variant="glyph"
                                size="lg"
                            />
                        }
                        title="No conversations yet"
                        description="Ask your mentor anything from any page; the conversation appears here."
                    />
                ) : (
                    <ListCard>
                        {conversations.map((c) => (
                            <ListRow
                                key={c.id}
                                onClick={() => open(c.id)}
                                icon={
                                    <IconTile
                                        icon={ChatCircle}
                                        tone="sky"
                                        variant="glyph"
                                        size="sm"
                                    />
                                }
                                title={c.title || 'Untitled chat'}
                                meta={
                                    hydrated ? relativeTime(c.updated_at) : null
                                }
                                className={
                                    mentor.conversationId === c.id
                                        ? 'bg-muted/60'
                                        : undefined
                                }
                            />
                        ))}
                    </ListCard>
                )}
            </Page>
        </>
    );
}

DashboardChatsIndex.layout = {
    breadcrumbs: [
        { title: 'Home', href: '/dashboard' },
        { title: 'Chat history', href: '/dashboard/chats' },
    ],
};
