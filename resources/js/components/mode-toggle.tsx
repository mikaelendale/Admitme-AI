import { MonitorIcon, MoonIcon, SunIcon } from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
const modes: { value: Appearance; icon: Icon; label: string }[] = [
    { value: 'light', icon: SunIcon, label: 'Light' },
    { value: 'dark', icon: MoonIcon, label: 'Dark' },
    { value: 'system', icon: MonitorIcon, label: 'System' },
];

const nextMode: Record<Appearance, Appearance> = {
    light: 'dark',
    dark: 'system',
    system: 'light',
};

export default function ModeToggle({ className }: { className?: string }) {
    const { appearance, updateAppearance } = useAppearance();
    const current = modes.find((m) => m.value === appearance) ?? modes[2]!;
    const upcoming = modes.find((m) => m.value === nextMode[appearance])!;
    const Icon = current.icon;

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className={cn('shrink-0 rounded-full', className)}
            onClick={() => updateAppearance(nextMode[appearance])}
            aria-label={`Theme: ${current.label}. Activate to switch to ${upcoming.label}.`}
        >
            <Icon className="size-5" aria-hidden />
        </Button>
    );
}
