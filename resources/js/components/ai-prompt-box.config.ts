/**
 * PromptInputBox — edit here
 * -------------------------
 * • `defaultPromptBoxFeatures` — turn UI pieces on/off (merged with `features` prop on `<PromptInputBox />`).
 * • `defaultPromptBoxCopy` — tooltips, mode placeholders (merged with `copy` prop).
 * • `promptBoxStyles` — Tailwind / layout classes (single place to restyle).
 * • `promptBoxLimits` — file size, textarea height, recorder bars.
 * • `promptBoxMotion` — framer-motion timings (image preview).
 */

export type PromptBoxFeatures = {
    /** Paperclip + file input */
    imageAttachment: boolean;
    /** Drop files onto the box */
    dragAndDrop: boolean;
    /** Paste image from clipboard */
    clipboardPaste: boolean;
    /** Web search mode chip */
    searchMode: boolean;
    /** “Think” mode chip */
    thinkMode: boolean;
    /** Canvas mode chip */
    canvasMode: boolean;
    /** Mic / voice recording (empty state) */
    voiceInput: boolean;
    /** Gradient divider between mode chips */
    modeDividers: boolean;
    /** Fullscreen image preview dialog */
    imagePreviewModal: boolean;
};

/** Default: everything on (matches original component). */
export const defaultPromptBoxFeatures: PromptBoxFeatures = {
    imageAttachment: true,
    dragAndDrop: true,
    clipboardPaste: true,
    searchMode: true,
    thinkMode: false,
    canvasMode: false,
    voiceInput: true,
    modeDividers: false,
    imagePreviewModal: true,
};

export type PromptBoxCopy = {
    defaultPlaceholder: string;
    searchPlaceholder: string;
    thinkPlaceholder: string;
    canvasPlaceholder: string;
    tooltipUpload: string;
    tooltipStopGeneration: string;
    tooltipStopRecording: string;
    tooltipSend: string;
    tooltipVoice: string;
};

export const defaultPromptBoxCopy: PromptBoxCopy = {
    defaultPlaceholder: 'Type your message here...',
    searchPlaceholder: 'Search the web...',
    thinkPlaceholder: 'Think deeply...',
    canvasPlaceholder: 'Create on canvas...',
    tooltipUpload: 'Upload image',
    tooltipStopGeneration: 'Stop generation',
    tooltipStopRecording: 'Stop recording',
    tooltipSend: 'Send message',
    tooltipVoice: 'Voice message',
};

export const promptBoxLimits = {
    maxImageBytes: 10 * 1024 * 1024,
    textareaMaxHeightDefault: 240 as number | string,
    voiceVisualizerBars: 32,
} as const;

export const promptBoxMotion = {
    imageDialog: { duration: 0.2, ease: 'easeOut' as const },
} as const;

/**
 * All visual classes in one map. Change strings here to restyle the box.
 */
export const promptBoxStyles = {
    /** Injected once into document.head (scrollbar + focus). */
    globalInjectedCss: `
  *:focus-visible {
    outline-offset: 0 !important;
    --ring-offset: 0 !important;
  }
  textarea::-webkit-scrollbar {
    width: 6px;
  }
  textarea::-webkit-scrollbar-track {
    background: transparent;
  }
  textarea::-webkit-scrollbar-thumb {
    background-color: var(--border);
    border-radius: 3px;
  }
  textarea::-webkit-scrollbar-thumb:hover {
    background-color: color-mix(in oklch, var(--muted-foreground) 45%, transparent);
  }
`,

    textarea:
        'flex w-full rounded-md border-none bg-transparent px-3 py-2.5 text-base text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-0 disabled:cursor-not-allowed disabled:opacity-50 min-h-[44px] resize-none',

    tooltipContent:
        'z-50 overflow-hidden rounded-md border border-border bg-popover px-3 py-1.5 text-sm text-popover-foreground shadow-md animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',

    dialogOverlay:
        'fixed inset-0 z-50 bg-foreground/60 backdrop-blur-sm data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',

    dialogContent:
        'fixed left-[50%] top-[50%] z-50 grid w-full max-w-[90vw] md:max-w-[800px] translate-x-[-50%] translate-y-[-50%] gap-4 border border-border bg-card p-0 text-card-foreground shadow-xl duration-300 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 rounded-2xl',

    dialogClose:
        'absolute right-4 top-4 z-10 rounded-full bg-muted/90 p-2 text-muted-foreground transition-all hover:bg-muted hover:text-foreground',

    dialogTitle: 'text-lg font-semibold leading-none tracking-tight text-foreground',

    internalButtonBase:
        'inline-flex items-center justify-center font-medium transition-colors focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50',
    internalButtonVariantDefault:
        'bg-primary text-primary-foreground hover:bg-primary/90',
    internalButtonVariantOutline:
        'border border-border bg-transparent hover:bg-muted',
    internalButtonVariantGhost: 'bg-transparent hover:bg-muted',
    internalButtonSizeDefault: 'h-10 px-4 py-2',
    internalButtonSizeSm: 'h-8 px-3 text-sm',
    internalButtonSizeLg: 'h-12 px-6',
    internalButtonSizeIcon: 'h-8 w-8 rounded-full aspect-[1/1]',

    voiceRecorderWrapper:
        'flex flex-col items-center justify-center w-full transition-all duration-300 py-3',
    voiceRecorderWrapperHidden: 'opacity-0 h-0',
    voiceRecorderWrapperVisible: 'opacity-100',
    voiceRecorderDot: 'h-2 w-2 rounded-full bg-destructive animate-pulse',
    voiceRecorderTime: 'font-mono text-sm text-muted-foreground',
    voiceRecorderBarsRow:
        'w-full h-10 flex items-center justify-center gap-0.5 px-4',
    voiceRecorderBar: 'w-0.5 rounded-full bg-muted-foreground/40 animate-pulse',

    imageDialogContent:
        'p-0 border-none bg-transparent shadow-none max-w-[90vw] md:max-w-[800px]',
    imageDialogFrame:
        'relative rounded-2xl overflow-hidden bg-card shadow-2xl ring-1 ring-border',
    imageDialogImg: 'w-full max-h-[80vh] object-contain rounded-2xl',

    promptInputSurface:
        'rounded-3xl border border-border bg-card p-2 text-card-foreground shadow-md ',
    promptInputSurfaceLoading: 'border-',
    promptInputOuter:
        'w-full  bg-card text-card-foreground shadow-none ring-3 ring-primary-foreground/30 ',
    promptInputOuterRecording: 'border-destructive/70',

    filePreviewRow: 'flex flex-wrap gap-2 p-0 pb-1 transition-all duration-300',
    filePreviewThumb:
        'w-16 h-16 rounded-xl overflow-hidden cursor-pointer transition-all duration-300',
    filePreviewRemove:
        'absolute top-1 right-1 rounded-full bg-foreground/80 p-0.5 text-background opacity-100 transition-opacity hover:bg-foreground',

    textareaWrapperHidden: 'h-0 overflow-hidden opacity-0',
    textareaWrapperVisible: 'opacity-100',

    actionsRow: 'flex items-center justify-between gap-2 p-0 pt-2',
    actionsLeft: 'flex items-center gap-1 transition-opacity duration-300',
    actionsLeftHidden: 'opacity-0 invisible h-0',
    actionsLeftVisible: 'opacity-100 visible',

    attachButton:
        'flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',

    modeChipBase:
        'rounded-full transition-all flex items-center gap-1 px-2 py-1 border h-8',
    modeChipInactive:
        'border-transparent bg-transparent text-muted-foreground hover:text-foreground',
    modeChipSearchActive: 'border-chart-1 bg-chart-1/15 text-chart-1',
    modeChipThinkActive: 'border-chart-2 bg-chart-2/15 text-chart-2',
    modeChipCanvasActive: 'border-chart-3 bg-chart-3/15 text-chart-3',

    modeChipIconWrap: 'w-5 h-5 flex items-center justify-center shrink-0',
    modeChipLabel:
        'shrink-0 overflow-hidden text-xs whitespace-nowrap',

    primarySendButton:
        'h-8 w-8 rounded-full transition-all duration-200',
    primarySendRecording:
        'bg-transparent text-destructive hover:bg-muted hover:text-destructive/90',
    primarySendHasContent: 'bg-primary text-primary-foreground hover:bg-primary/90',
    primarySendIdle:
        'bg-transparent text-muted-foreground hover:bg-muted hover:text-foreground',

    customDividerOuter: 'relative h-6 w-[1.5px] mx-1',
    customDividerInner:
        'absolute inset-0 rounded-full bg-gradient-to-t from-transparent via-primary/35 to-transparent',
} as const;
