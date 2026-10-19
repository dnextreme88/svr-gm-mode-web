import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { MatchFamilyLabels, MoraleLabels } from '@/types/enums';
import type { MatchFamily, Morale } from '@/types/enums';
import type { InjuryInfo } from '@/types/gm';

// Solid backgrounds; each text colour keeps at least 4.5:1 contrast.
const moraleStyles: Record<Morale, string> = {
    happy: 'bg-zone-safe text-arena ring-zone-safe',
    content: 'bg-zone-minor text-arena ring-zone-minor',
    unsatisfied: 'bg-gm-orange text-arena ring-gm-orange',
    upset: 'bg-gm-red-deep text-white ring-gm-red-deep',
};

export function MoraleBadge({ morale }: { morale: Morale }) {
    return (
        <span
            data-morale={morale}
            className={cn(
                'inline-flex items-center px-2 py-0.5 font-condensed text-xs font-bold tracking-wider uppercase ring-1',
                moraleStyles[morale],
            )}
        >
            {MoraleLabels[morale]}
        </span>
    );
}

export function InjuryChip({ injury }: { injury: InjuryInfo | null }) {
    if (!injury) {
        return <span className="text-ink-faint">—</span>;
    }

    const serious = injury.severity === 'serious';

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 px-2 py-0.5 font-condensed text-xs font-semibold tracking-wide ring-1',
                serious
                    ? 'bg-zone-serious/15 text-zone-serious ring-zone-serious/40'
                    : 'bg-zone-minor/10 text-zone-minor ring-zone-minor/30',
            )}
            title={`${injury.label}: ${injury.severity}, ${injury.weeks_left} week(s) left`}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    serious ? 'bg-zone-serious' : 'bg-zone-minor',
                )}
            />
            {injury.label}
            <span className="opacity-70">· {injury.weeks_left}w</span>
        </span>
    );
}

export function SpecialtyBadge({
    family,
    active = false,
}: {
    family: MatchFamily;
    active?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center px-1.5 py-px font-condensed text-xs font-bold tracking-widest uppercase',
                active
                    ? 'bg-gold text-arena shadow-[0_0_12px_-2px_var(--color-gold)]'
                    : 'bg-white/6 text-ink-dim ring-1 ring-white/10',
            )}
            title={`${MatchFamilyLabels[family]} specialist`}
        >
            {active && '★ '}
            {MatchFamilyLabels[family]}
        </span>
    );
}

export function TitleBelt({ name }: { name: string }) {
    return (
        <span
            className="inline-flex items-center gap-1 bg-gradient-to-b from-gold-hot to-gold px-1.5 py-px font-condensed text-xs font-bold tracking-wider text-arena uppercase"
            title={name}
        >
            ◆ {name.replace(' Championship', '')}
        </span>
    );
}

/** Red pill for unread mail: the inbox list and the nav badge. */
export function UnreadPill({ children }: { children: ReactNode }) {
    return (
        <span className="inline-flex min-w-5 items-center justify-center rounded-full bg-gm-red-deep px-2 py-px font-condensed text-xs font-bold tracking-wider text-white uppercase">
            {children}
        </span>
    );
}
