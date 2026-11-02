import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowsClockwise, Brain, ChatCircle } from '@phosphor-icons/react';
import { useState } from 'react';
import { KeyValueList, KeyValueRow, Section } from '@/components/page/kit';
import {
    fieldClass,
    FieldRow,
    FormFooter,
    Notice,
    pillButton,
    RowList,
    settingsBreadcrumbs,
    UnsavedChanges,
} from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

type Prefs = {
    mentor_tone: string;
    essay_feedback_style: string;
};

const FORM_ID = 'ai-preferences-form';

export default function AiSettings({
    preferences,
    aiContextLastUpdated,
}: {
    preferences: Prefs;
    aiContextLastUpdated: string | null;
}) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    // Bumped by "Discard" so the selects show their saved values again.
    const [selectsKey, setSelectsKey] = useState(0);

    return (
        <>
            <Head title="AI preferences" />

            {flash?.status === 'ai-preferences-updated' ||
            flash?.status === 'ai-context-regeneration-queued' ? (
                <Notice tone="success" className="mb-8">
                    {flash?.status === 'ai-preferences-updated'
                        ? 'Preferences saved.'
                        : 'Profile summary refresh queued — check back in a minute.'}
                </Notice>
            ) : null}

            <Section
                title="Mentor"
                icon={ChatCircle}
                description="How your mentor and essay feedback sound."
            >
                <Form
                    id={FORM_ID}
                    action="/settings/ai"
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
                                onDiscard={() => {
                                    reset();
                                    setSelectsKey((k) => k + 1);
                                }}
                            />

                            <RowList key={selectsKey}>
                                <FieldRow
                                    label="Mentor tone"
                                    htmlFor="mentor_tone"
                                >
                                    <Select
                                        defaultValue={preferences.mentor_tone}
                                        name="mentor_tone"
                                    >
                                        <SelectTrigger
                                            id="mentor_tone"
                                            className={cn(fieldClass, 'w-full')}
                                        >
                                            <SelectValue placeholder="Select mentor tone" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="brutally_honest">
                                                Brutally honest
                                            </SelectItem>
                                            <SelectItem value="supportive">
                                                Supportive
                                            </SelectItem>
                                            <SelectItem value="balanced">
                                                Balanced
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </FieldRow>
                                <FieldRow
                                    label="Essay feedback"
                                    htmlFor="essay_feedback_style"
                                >
                                    <Select
                                        defaultValue={
                                            preferences.essay_feedback_style
                                        }
                                        name="essay_feedback_style"
                                    >
                                        <SelectTrigger
                                            id="essay_feedback_style"
                                            className={cn(fieldClass, 'w-full')}
                                        >
                                            <SelectValue placeholder="Select feedback style" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="detailed_constructive">
                                                Detailed & constructive
                                            </SelectItem>
                                            <SelectItem value="concise">
                                                Concise
                                            </SelectItem>
                                            <SelectItem value="line_by_line">
                                                Line by line
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </FieldRow>
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

            <Section
                title="Knowledge base"
                icon={Brain}
                description="A compact summary of your profile that chat and tools read. It refreshes after onboarding and when you regenerate it."
            >
                <KeyValueList className="border-y border-border">
                    <KeyValueRow
                        label="Last updated"
                        hint={
                            aiContextLastUpdated
                                ? undefined
                                : 'Complete onboarding or regenerate.'
                        }
                        value={
                            aiContextLastUpdated ?? (
                                <span className="font-normal text-muted-foreground">
                                    Not built yet
                                </span>
                            )
                        }
                    />
                </KeyValueList>

                <p className="mt-4 text-[13px] leading-relaxed text-muted-foreground">
                    It is built from your profile wizard (brain dump, goals and
                    review). Edit that to change what the AI knows, then
                    regenerate the summary here.
                </p>
                <div className="mt-3 flex flex-wrap justify-end gap-2">
                    <Button asChild variant="ghost" className={pillButton}>
                        <Link href="/profile-overview?wizard=1">
                            Edit profile knowledge
                        </Link>
                    </Button>
                    <Form
                        action="/settings/ai/regenerate-context"
                        method="post"
                        options={{ preserveScroll: true }}
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            className={pillButton}
                        >
                            <ArrowsClockwise className="size-4" />
                            Regenerate profile summary
                        </Button>
                    </Form>
                </div>
            </Section>
        </>
    );
}

AiSettings.layout = {
    breadcrumbs: settingsBreadcrumbs('AI', '/settings/ai'),
};
