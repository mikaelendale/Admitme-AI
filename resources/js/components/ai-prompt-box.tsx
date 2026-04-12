import React from "react";
import * as TooltipPrimitive from "@radix-ui/react-tooltip";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { ArrowUp, Paperclip, Square, X, StopCircle, Globe, BrainIcon, FolderIcon, MicrophoneIcon } from "@phosphor-icons/react";
import { motion, AnimatePresence } from "framer-motion";
import { cn } from "@/lib/utils";
import {
  defaultPromptBoxCopy,
  defaultPromptBoxFeatures,
  promptBoxLimits,
  promptBoxMotion,
  promptBoxStyles,
  type PromptBoxCopy,
  type PromptBoxFeatures,
} from "@/components/ai-prompt-box.config";

export type { PromptBoxCopy, PromptBoxFeatures } from "@/components/ai-prompt-box.config";

// Inject global scrollbar / focus styles once
const styleSheet = document.createElement("style");
styleSheet.innerText = promptBoxStyles.globalInjectedCss;
document.head.appendChild(styleSheet);

interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  className?: string;
}
const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(({ className, ...props }, ref) => (
  <textarea
    className={cn(promptBoxStyles.textarea, className)}
    ref={ref}
    rows={2}
    {...props}
  />
));
Textarea.displayName = "Textarea";

const TooltipProvider = TooltipPrimitive.Provider;
const Tooltip = TooltipPrimitive.Root;
const TooltipTrigger = TooltipPrimitive.Trigger;
const TooltipContent = React.forwardRef<
  React.ElementRef<typeof TooltipPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof TooltipPrimitive.Content>
>(({ className, sideOffset = 4, ...props }, ref) => (
  <TooltipPrimitive.Content
    ref={ref}
    sideOffset={sideOffset}
    className={cn(promptBoxStyles.tooltipContent, className)}
    {...props}
  />
));
TooltipContent.displayName = TooltipPrimitive.Content.displayName;

const Dialog = DialogPrimitive.Root;
const DialogPortal = DialogPrimitive.Portal;
const DialogOverlay = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn(promptBoxStyles.dialogOverlay, className)}
    {...props}
  />
));
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

const DialogContent = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>
>(({ className, children, ...props }, ref) => (
  <DialogPortal>
    <DialogOverlay />
    <DialogPrimitive.Content
      ref={ref}
      className={cn(promptBoxStyles.dialogContent, className)}
      {...props}
    >
      {children}
      <DialogPrimitive.Close className={promptBoxStyles.dialogClose}>
        <X className="h-5 w-5" />
        <span className="sr-only">Close</span>
      </DialogPrimitive.Close>
    </DialogPrimitive.Content>
  </DialogPortal>
));
DialogContent.displayName = DialogPrimitive.Content.displayName;

const DialogTitle = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title
    ref={ref}
    className={cn(promptBoxStyles.dialogTitle, className)}
    {...props}
  />
));
DialogTitle.displayName = DialogPrimitive.Title.displayName;

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: "default" | "outline" | "ghost";
  size?: "default" | "sm" | "lg" | "icon";
}
const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant = "default", size = "default", ...props }, ref) => {
    const variantClasses = {
      default: promptBoxStyles.internalButtonVariantDefault,
      outline: promptBoxStyles.internalButtonVariantOutline,
      ghost: promptBoxStyles.internalButtonVariantGhost,
    };
    const sizeClasses = {
      default: promptBoxStyles.internalButtonSizeDefault,
      sm: promptBoxStyles.internalButtonSizeSm,
      lg: promptBoxStyles.internalButtonSizeLg,
      icon: promptBoxStyles.internalButtonSizeIcon,
    };
    return (
      <button
        className={cn(
          promptBoxStyles.internalButtonBase,
          variantClasses[variant],
          sizeClasses[size],
          className
        )}
        ref={ref}
        {...props}
      />
    );
  }
);
Button.displayName = "Button";

interface VoiceRecorderProps {
  isRecording: boolean;
  onStartRecording: () => void;
  onStopRecording: (duration: number) => void;
  visualizerBars?: number;
}
const VoiceRecorder: React.FC<VoiceRecorderProps> = ({
  isRecording,
  onStartRecording,
  onStopRecording,
  visualizerBars = promptBoxLimits.voiceVisualizerBars,
}) => {
  const [time, setTime] = React.useState(0);
  const timerRef = React.useRef<ReturnType<typeof setInterval> | null>(null);

  React.useEffect(() => {
    if (isRecording) {
      onStartRecording();
      timerRef.current = setInterval(() => setTime((t) => t + 1), 1000);
    } else {
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
      onStopRecording(time);
      setTime(0);
    }
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
    };
  }, [isRecording, time, onStartRecording, onStopRecording]);

  const formatTime = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
  };

  return (
    <div
      className={cn(
        promptBoxStyles.voiceRecorderWrapper,
        isRecording ? promptBoxStyles.voiceRecorderWrapperVisible : promptBoxStyles.voiceRecorderWrapperHidden
      )}
    >
      <div className="flex items-center gap-2 mb-3">
        <div className={promptBoxStyles.voiceRecorderDot} />
        <span className={promptBoxStyles.voiceRecorderTime}>{formatTime(time)}</span>
      </div>
      <div className={promptBoxStyles.voiceRecorderBarsRow}>
        {[...Array(visualizerBars)].map((_, i) => (
          <div
            key={i}
            className={promptBoxStyles.voiceRecorderBar}
            style={{
              height: `${Math.max(15, Math.random() * 100)}%`,
              animationDelay: `${i * 0.05}s`,
              animationDuration: `${0.5 + Math.random() * 0.5}s`,
            }}
          />
        ))}
      </div>
    </div>
  );
};

interface ImageViewDialogProps {
  imageUrl: string | null;
  onClose: () => void;
  enabled: boolean;
}
const ImageViewDialog: React.FC<ImageViewDialogProps> = ({ imageUrl, onClose, enabled }) => {
  if (!enabled || !imageUrl) return null;
  return (
    <Dialog open={!!imageUrl} onOpenChange={onClose}>
      <DialogContent className={promptBoxStyles.imageDialogContent}>
        <DialogTitle className="sr-only">Image Preview</DialogTitle>
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          exit={{ opacity: 0, scale: 0.95 }}
          transition={promptBoxMotion.imageDialog}
          className={promptBoxStyles.imageDialogFrame}
        >
          <img
            src={imageUrl}
            alt="Full preview"
            className={promptBoxStyles.imageDialogImg}
          />
        </motion.div>
      </DialogContent>
    </Dialog>
  );
};

interface PromptInputContextType {
  isLoading: boolean;
  value: string;
  setValue: (value: string) => void;
  maxHeight: number | string;
  onSubmit?: () => void;
  disabled?: boolean;
}
const PromptInputContext = React.createContext<PromptInputContextType>({
  isLoading: false,
  value: "",
  setValue: () => {},
  maxHeight: promptBoxLimits.textareaMaxHeightDefault,
  onSubmit: undefined,
  disabled: false,
});
function usePromptInput() {
  const context = React.useContext(PromptInputContext);
  if (!context) throw new Error("usePromptInput must be used within a PromptInput");
  return context;
}

interface PromptInputProps {
  isLoading?: boolean;
  value?: string;
  onValueChange?: (value: string) => void;
  maxHeight?: number | string;
  onSubmit?: () => void;
  children: React.ReactNode;
  className?: string;
  disabled?: boolean;
  onDragOver?: (e: React.DragEvent) => void;
  onDragLeave?: (e: React.DragEvent) => void;
  onDrop?: (e: React.DragEvent) => void;
}
const PromptInput = React.forwardRef<HTMLDivElement, PromptInputProps>(
  (
    {
      className,
      isLoading = false,
      maxHeight = promptBoxLimits.textareaMaxHeightDefault,
      value,
      onValueChange,
      onSubmit,
      children,
      disabled = false,
      onDragOver,
      onDragLeave,
      onDrop,
    },
    ref
  ) => {
    const [internalValue, setInternalValue] = React.useState(value || "");
    const handleChange = (newValue: string) => {
      setInternalValue(newValue);
      onValueChange?.(newValue);
    };
    return (
      <TooltipProvider>
        <PromptInputContext.Provider
          value={{
            isLoading,
            value: value ?? internalValue,
            setValue: onValueChange ?? handleChange,
            maxHeight,
            onSubmit,
            disabled,
          }}
        >
          <div
            ref={ref}
            className={cn(
              promptBoxStyles.promptInputSurface,
              isLoading && promptBoxStyles.promptInputSurfaceLoading,
              className
            )}
            onDragOver={onDragOver}
            onDragLeave={onDragLeave}
            onDrop={onDrop}
          >
            {children}
          </div>
        </PromptInputContext.Provider>
      </TooltipProvider>
    );
  }
);
PromptInput.displayName = "PromptInput";

interface PromptInputTextareaProps {
  disableAutosize?: boolean;
  placeholder?: string;
}
const PromptInputTextarea: React.FC<PromptInputTextareaProps & React.ComponentProps<typeof Textarea>> = ({
  className,
  onKeyDown,
  disableAutosize = false,
  placeholder,
  ...props
}) => {
  const { value, setValue, maxHeight, onSubmit, disabled } = usePromptInput();
  const textareaRef = React.useRef<HTMLTextAreaElement>(null);

  React.useEffect(() => {
    if (disableAutosize || !textareaRef.current) return;
    textareaRef.current.style.height = "auto";
    textareaRef.current.style.height =
      typeof maxHeight === "number"
        ? `${Math.min(textareaRef.current.scrollHeight, maxHeight)}px`
        : `min(${textareaRef.current.scrollHeight}px, ${maxHeight})`;
  }, [value, maxHeight, disableAutosize]);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      onSubmit?.();
    }
    onKeyDown?.(e);
  };

  return (
    <Textarea
      ref={textareaRef}
      value={value}
      onChange={(e) => setValue(e.target.value)}
      onKeyDown={handleKeyDown}
      className={cn("text-sm", className)}
      disabled={disabled}
      placeholder={placeholder}
      {...props}
    />
  );
};

const PromptInputActions: React.FC<React.HTMLAttributes<HTMLDivElement>> = ({ children, className, ...props }) => (
  <div className={cn(promptBoxStyles.actionsRow, className)} {...props}>
    {children}
  </div>
);

interface PromptInputActionProps extends React.ComponentProps<typeof Tooltip> {
  tooltip: React.ReactNode;
  children: React.ReactNode;
  side?: "top" | "bottom" | "left" | "right";
  className?: string;
}
const PromptInputAction: React.FC<PromptInputActionProps> = ({
  tooltip,
  children,
  className,
  side = "top",
  ...props
}) => {
  const { disabled } = usePromptInput();
  return (
    <Tooltip {...props}>
      <TooltipTrigger asChild disabled={disabled}>
        {children}
      </TooltipTrigger>
      <TooltipContent side={side} className={className}>
        {tooltip}
      </TooltipContent>
    </Tooltip>
  );
};

const CustomDivider: React.FC = () => (
  <div className={promptBoxStyles.customDividerOuter}>
    <div
      className={promptBoxStyles.customDividerInner}
      style={{
        clipPath: "polygon(0% 0%, 100% 0%, 100% 40%, 140% 50%, 100% 60%, 100% 100%, 0% 100%, 0% 60%, -40% 50%, 0% 40%)",
      }}
    />
  </div>
);

function mergePromptBoxFeatures(override?: Partial<PromptBoxFeatures>): PromptBoxFeatures {
  return { ...defaultPromptBoxFeatures, ...override };
}

function mergePromptBoxCopy(override?: Partial<PromptBoxCopy>): PromptBoxCopy {
  return { ...defaultPromptBoxCopy, ...override };
}

export interface PromptInputBoxProps {
  onSend?: (message: string, files?: File[]) => void;
  isLoading?: boolean;
  placeholder?: string;
  className?: string;
  /** Toggle sub-features without editing this file — merges with defaults in ai-prompt-box.config.ts */
  features?: Partial<PromptBoxFeatures>;
  /** Override labels / tooltips — merges with defaults in ai-prompt-box.config.ts */
  copy?: Partial<PromptBoxCopy>;
}

export const PromptInputBox = React.forwardRef<HTMLDivElement, PromptInputBoxProps>((props, ref) => {
  const {
    onSend = () => {},
    isLoading = false,
    placeholder: placeholderProp,
    className,
    features: featuresProp,
    copy: copyProp,
  } = props;

  const features = React.useMemo(() => mergePromptBoxFeatures(featuresProp), [featuresProp]);
  const copy = React.useMemo(() => mergePromptBoxCopy(copyProp), [copyProp]);
  const placeholder = placeholderProp ?? copy.defaultPlaceholder;

  const [input, setInput] = React.useState("");
  const [files, setFiles] = React.useState<File[]>([]);
  const [filePreviews, setFilePreviews] = React.useState<{ [key: string]: string }>({});
  const [selectedImage, setSelectedImage] = React.useState<string | null>(null);
  const [isRecording, setIsRecording] = React.useState(false);
  const [showSearch, setShowSearch] = React.useState(false);
  const [showThink, setShowThink] = React.useState(false);
  const [showCanvas, setShowCanvas] = React.useState(false);
  const uploadInputRef = React.useRef<HTMLInputElement>(null);
  const promptBoxRef = React.useRef<HTMLDivElement>(null);

  const handleToggleChange = (value: string) => {
    if (value === "search") {
      setShowSearch((prev) => !prev);
      setShowThink(false);
    } else if (value === "think") {
      setShowThink((prev) => !prev);
      setShowSearch(false);
    }
  };

  const handleCanvasToggle = () => setShowCanvas((prev) => !prev);

  const isImageFile = (file: File) => file.type.startsWith("image/");

  const processFile = React.useCallback(
    (file: File) => {
      if (!features.imageAttachment) return;
      if (!isImageFile(file)) {
        console.log("Only image files are allowed");
        return;
      }
      if (file.size > promptBoxLimits.maxImageBytes) {
        console.log("File too large (max 10MB)");
        return;
      }
      setFiles([file]);
      const reader = new FileReader();
      reader.onload = (e) => setFilePreviews({ [file.name]: e.target?.result as string });
      reader.readAsDataURL(file);
    },
    [features.imageAttachment]
  );

  const handleDragOver = React.useCallback(
    (e: React.DragEvent) => {
      if (!features.dragAndDrop || !features.imageAttachment) return;
      e.preventDefault();
      e.stopPropagation();
    },
    [features.dragAndDrop, features.imageAttachment]
  );

  const handleDragLeave = React.useCallback(
    (e: React.DragEvent) => {
      if (!features.dragAndDrop || !features.imageAttachment) return;
      e.preventDefault();
      e.stopPropagation();
    },
    [features.dragAndDrop, features.imageAttachment]
  );

  const handleDrop = React.useCallback(
    (e: React.DragEvent) => {
      if (!features.dragAndDrop || !features.imageAttachment) return;
      e.preventDefault();
      e.stopPropagation();
      const dropped = Array.from(e.dataTransfer.files);
      const imageFiles = dropped.filter((file) => isImageFile(file));
      if (imageFiles.length > 0) processFile(imageFiles[0]);
    },
    [features.dragAndDrop, features.imageAttachment, processFile]
  );

  const handleRemoveFile = (index: number) => {
    const fileToRemove = files[index];
    if (fileToRemove && filePreviews[fileToRemove.name]) setFilePreviews({});
    setFiles([]);
  };

  const openImageModal = (imageUrl: string) => {
    if (features.imagePreviewModal) setSelectedImage(imageUrl);
  };

  const handlePaste = React.useCallback(
    (e: ClipboardEvent) => {
      if (!features.clipboardPaste || !features.imageAttachment) return;
      const items = e.clipboardData?.items;
      if (!items) return;
      for (let i = 0; i < items.length; i++) {
        if (items[i].type.indexOf("image") !== -1) {
          const file = items[i].getAsFile();
          if (file) {
            e.preventDefault();
            processFile(file);
            break;
          }
        }
      }
    },
    [features.clipboardPaste, features.imageAttachment, processFile]
  );

  React.useEffect(() => {
    if (!features.clipboardPaste || !features.imageAttachment) return;
    document.addEventListener("paste", handlePaste);
    return () => document.removeEventListener("paste", handlePaste);
  }, [features.clipboardPaste, features.imageAttachment, handlePaste]);

  const handleSubmit = () => {
    if (input.trim() || files.length > 0) {
      let messagePrefix = "";
      if (features.searchMode && showSearch) messagePrefix = "[Search: ";
      else if (features.thinkMode && showThink) messagePrefix = "[Think: ";
      else if (features.canvasMode && showCanvas) messagePrefix = "[Canvas: ";
      const formattedInput = messagePrefix ? `${messagePrefix}${input}]` : input;
      onSend(formattedInput, files);
      setInput("");
      setFiles([]);
      setFilePreviews({});
    }
  };

  const handleStartRecording = () => console.log("Started recording");

  const handleStopRecording = (duration: number) => {
    console.log(`Stopped recording after ${duration} seconds`);
    setIsRecording(false);
    onSend(`[Voice message - ${duration} seconds]`, []);
  };

  const hasContent = input.trim() !== "" || files.length > 0;
  const anyModeChip = features.searchMode || features.thinkMode || features.canvasMode;
  const showLeftTools = features.imageAttachment || anyModeChip;

  const modeChipNodes: React.ReactElement[] = [];
  if (features.searchMode) {
    modeChipNodes.push(
      <button
        key="search"
        type="button"
        onClick={() => handleToggleChange("search")}
        className={cn(
          promptBoxStyles.modeChipBase,
          showSearch ? promptBoxStyles.modeChipSearchActive : promptBoxStyles.modeChipInactive
        )}
      >
        <div className={promptBoxStyles.modeChipIconWrap}>
          <motion.div
            animate={{ rotate: showSearch ? 360 : 0, scale: showSearch ? 1.1 : 1 }}
            whileHover={{ rotate: showSearch ? 360 : 15, scale: 1.1, transition: { type: "spring", stiffness: 300, damping: 10 } }}
            transition={{ type: "spring", stiffness: 260, damping: 25 }}
          >
            <Globe className={cn("w-4 h-4", showSearch ? "text-chart-1" : "text-inherit")} />
          </motion.div>
        </div>
        <AnimatePresence>
          {showSearch && (
            <motion.span
              initial={{ width: 0, opacity: 0 }}
              animate={{ width: "auto", opacity: 1 }}
              exit={{ width: 0, opacity: 0 }}
              transition={{ duration: 0.2 }}
              className={cn(promptBoxStyles.modeChipLabel, "text-chart-1")}
            >
              Search
            </motion.span>
          )}
        </AnimatePresence>
      </button>
    );
  }
  if (features.thinkMode) {
    modeChipNodes.push(
      <button
        key="think"
        type="button"
        onClick={() => handleToggleChange("think")}
        className={cn(
          promptBoxStyles.modeChipBase,
          showThink ? promptBoxStyles.modeChipThinkActive : promptBoxStyles.modeChipInactive
        )}
      >
        <div className={promptBoxStyles.modeChipIconWrap}>
          <motion.div
            animate={{ rotate: showThink ? 360 : 0, scale: showThink ? 1.1 : 1 }}
            whileHover={{ rotate: showThink ? 360 : 15, scale: 1.1, transition: { type: "spring", stiffness: 300, damping: 10 } }}
            transition={{ type: "spring", stiffness: 260, damping: 25 }}
          >
            <BrainIcon className={cn("w-4 h-4", showThink ? "text-chart-2" : "text-inherit")} />
          </motion.div>
        </div>
        <AnimatePresence>
          {showThink && (
            <motion.span
              initial={{ width: 0, opacity: 0 }}
              animate={{ width: "auto", opacity: 1 }}
              exit={{ width: 0, opacity: 0 }}
              transition={{ duration: 0.2 }}
              className={cn(promptBoxStyles.modeChipLabel, "text-chart-2")}
            >
              Think
            </motion.span>
          )}
        </AnimatePresence>
      </button>
    );
  }
  if (features.canvasMode) {
    modeChipNodes.push(
      <button
        key="canvas"
        type="button"
        onClick={handleCanvasToggle}
        className={cn(
          promptBoxStyles.modeChipBase,
          showCanvas ? promptBoxStyles.modeChipCanvasActive : promptBoxStyles.modeChipInactive
        )}
      >
        <div className={promptBoxStyles.modeChipIconWrap}>
          <motion.div
            animate={{ rotate: showCanvas ? 360 : 0, scale: showCanvas ? 1.1 : 1 }}
            whileHover={{ rotate: showCanvas ? 360 : 15, scale: 1.1, transition: { type: "spring", stiffness: 300, damping: 10 } }}
            transition={{ type: "spring", stiffness: 260, damping: 25 }}
          >
            <FolderIcon className={cn("w-4 h-4", showCanvas ? "text-chart-3" : "text-inherit")} />
          </motion.div>
        </div>
        <AnimatePresence>
          {showCanvas && (
            <motion.span
              initial={{ width: 0, opacity: 0 }}
              animate={{ width: "auto", opacity: 1 }}
              exit={{ width: 0, opacity: 0 }}
              transition={{ duration: 0.2 }}
              className={cn(promptBoxStyles.modeChipLabel, "text-chart-3")}
            >
              Canvas
            </motion.span>
          )}
        </AnimatePresence>
      </button>
    );
  }

  const primaryTooltip =
    isLoading
      ? copy.tooltipStopGeneration
      : isRecording
        ? copy.tooltipStopRecording
        : hasContent
          ? copy.tooltipSend
          : copy.tooltipVoice;

  const textareaPlaceholder =
    showSearch && features.searchMode
      ? copy.searchPlaceholder
      : showThink && features.thinkMode
        ? copy.thinkPlaceholder
        : showCanvas && features.canvasMode
          ? copy.canvasPlaceholder
          : placeholder;

  return (
    <>
      <PromptInput
        value={input}
        onValueChange={setInput}
        isLoading={isLoading}
        onSubmit={handleSubmit}
        className={cn(
          promptBoxStyles.promptInputOuter,
          isRecording && promptBoxStyles.promptInputOuterRecording,
          className
        )}
        disabled={isLoading || isRecording}
        ref={ref ?? promptBoxRef}
        onDragOver={features.dragAndDrop && features.imageAttachment ? handleDragOver : undefined}
        onDragLeave={features.dragAndDrop && features.imageAttachment ? handleDragLeave : undefined}
        onDrop={features.dragAndDrop && features.imageAttachment ? handleDrop : undefined}
      >
        {features.imageAttachment && files.length > 0 && !isRecording && (
          <div className={promptBoxStyles.filePreviewRow}>
            {files.map((file, index) => (
              <div key={index} className="relative group">
                {file.type.startsWith("image/") && filePreviews[file.name] && (
                  <div
                    className={promptBoxStyles.filePreviewThumb}
                    onClick={() => openImageModal(filePreviews[file.name])}
                    onKeyDown={(e) => e.key === "Enter" && openImageModal(filePreviews[file.name])}
                    role={features.imagePreviewModal ? "button" : undefined}
                    tabIndex={features.imagePreviewModal ? 0 : undefined}
                  >
                    <img
                      src={filePreviews[file.name]}
                      alt={file.name}
                      className="h-full w-full object-cover"
                    />
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        handleRemoveFile(index);
                      }}
                      className={promptBoxStyles.filePreviewRemove}
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}

        <div
          className={cn(
            "transition-all duration-300",
            isRecording ? promptBoxStyles.textareaWrapperHidden : promptBoxStyles.textareaWrapperVisible
          )}
        >
          <PromptInputTextarea placeholder={textareaPlaceholder} className="text-sm" />
        </div>

        {features.voiceInput && isRecording && (
          <VoiceRecorder
            isRecording={isRecording}
            onStartRecording={handleStartRecording}
            onStopRecording={handleStopRecording}
          />
        )}

        <PromptInputActions>
          <div
            className={cn(
              promptBoxStyles.actionsLeft,
              isRecording ? promptBoxStyles.actionsLeftHidden : promptBoxStyles.actionsLeftVisible
            )}
          >
            {showLeftTools && (
              <>
                {features.imageAttachment && (
                  <PromptInputAction tooltip={copy.tooltipUpload}>
                    <button
                      type="button"
                      onClick={() => uploadInputRef.current?.click()}
                      className={promptBoxStyles.attachButton}
                      disabled={isRecording}
                    >
                      <Paperclip className="h-5 w-5 transition-colors" />
                      <input
                        ref={uploadInputRef}
                        type="file"
                        className="hidden"
                        onChange={(e) => {
                          if (e.target.files && e.target.files.length > 0) processFile(e.target.files[0]);
                          if (e.target) e.target.value = "";
                        }}
                        accept="image/*"
                      />
                    </button>
                  </PromptInputAction>
                )}

                {anyModeChip && (
                  <div className="flex items-center">
                    {modeChipNodes.map((node, i) => (
                      <React.Fragment key={node.key ?? i}>
                        {i > 0 && features.modeDividers && <CustomDivider />}
                        {node}
                      </React.Fragment>
                    ))}
                  </div>
                )}
              </>
            )}
          </div>

          <PromptInputAction tooltip={primaryTooltip}>
            <Button
              variant="default"
              size="icon"
              className={cn(
                promptBoxStyles.primarySendButton,
                isRecording
                  ? promptBoxStyles.primarySendRecording
                  : hasContent
                    ? promptBoxStyles.primarySendHasContent
                    : promptBoxStyles.primarySendIdle
              )}
              onClick={() => {
                if (isRecording) setIsRecording(false);
                else if (hasContent) handleSubmit();
                else if (features.voiceInput) setIsRecording(true);
              }}
              disabled={(isLoading && !hasContent) || (!hasContent && !features.voiceInput)}
            >
              {isLoading ? (
                <Square className="h-4 w-4 fill-primary-foreground animate-pulse" />
              ) : isRecording ? (
                <StopCircle className="h-5 w-5 text-destructive" />
              ) : hasContent ? (
                <ArrowUp className="h-4 w-4 text-primary-foreground" />
              ) : (
                <MicrophoneIcon className="h-5 w-5 text-muted-foreground transition-colors" />
              )}
            </Button>
          </PromptInputAction>
        </PromptInputActions>
      </PromptInput>

      <ImageViewDialog
        imageUrl={selectedImage}
        onClose={() => setSelectedImage(null)}
        enabled={features.imagePreviewModal}
      />
    </>
  );
});
PromptInputBox.displayName = "PromptInputBox";
