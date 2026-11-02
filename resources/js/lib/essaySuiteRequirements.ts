export const ESSAY_TYPE_VALUES = [
    'common_app',
    'supplemental',
    'why_school',
] as const;

export type EssayTypeValue = (typeof ESSAY_TYPE_VALUES)[number];

export const ESSAY_TYPE_OPTIONS: {
    value: EssayTypeValue;
    label: string;
    hint: string;
}[] = [
    {
        value: 'common_app',
        label: 'Common App',
        hint: 'Personal statement / main Common App essay',
    },
    {
        value: 'supplemental',
        label: 'Supplemental',
        hint: 'School-specific short or long supplement',
    },
    {
        value: 'why_school',
        label: 'Why this school',
        hint: 'Fit, programs, and campus connection',
    },
];

export const MENTOR_TONE_VALUES = [
    'brutally_honest',
    'balanced',
    'supportive',
] as const;

export type MentorToneValue = (typeof MENTOR_TONE_VALUES)[number];

export const MENTOR_TONE_OPTIONS: { value: MentorToneValue; label: string }[] =
    [
        { value: 'brutally_honest', label: 'Brutally honest' },
        { value: 'balanced', label: 'Balanced' },
        { value: 'supportive', label: 'Supportive' },
    ];

export function coerceMentorTone(
    raw: string | undefined | null,
): MentorToneValue {
    if (raw === 'brutally_honest' || raw === 'supportive') {
        return raw;
    }

    return 'balanced';
}
