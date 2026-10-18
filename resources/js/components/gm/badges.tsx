import { cn } from '@/lib/utils';
import { MatchFamilyLabels, MoraleLabels } from '@/types/enums';
import type { MatchFamily, Morale } from '@/types/enums';
import type { InjuryInfo } from '@/types/gm';

const moraleStyles: Record<Morale, string> = {
    happy: 'text-zone-safe bg-zone-safe/10 ring-zone-safe/30',
    content: 'text-ink bg-white/5 ring-white/15',
    unsatisfied: 'text-zone-minor bg-zone-minor/10 ring-zone-minor/30',
    upset: 'text-zone-serious bg-zone-serious/10 ring-zone-serious/40',
};

export function MoraleBadge({ morale }: { morale: Morale }) {
    return (
        <span
            className={cn(
                'inline-flex items-center px-2 py-0.5 font-condensed text-[0.7rem] font-semibold tracking-wider uppercase ring-1',
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
                'inline-flex items-center gap-1.5 px-2 py-0.5 font-condensed text-[0.72rem] font-semibold tracking-wide ring-1',
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
                'inline-flex items-center px-1.5 py-px font-condensed text-[0.62rem] font-bold tracking-widest uppercase',
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
            className="inline-flex items-center gap-1 bg-gradient-to-b from-gold-hot to-gold px-1.5 py-px font-condensed text-[0.62rem] font-bold tracking-wider text-arena uppercase"
            title={name}
        >
            ◆ {name.replace(' Championship', '')}
        </span>
    );
}
