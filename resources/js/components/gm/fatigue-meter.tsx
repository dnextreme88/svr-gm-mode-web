import { capFatigue, fatigueZone, zoneLabel } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { FatigueConfig } from '@/types/gm';

type Props = {
    fatigue: number;
    /** Fatigue after the bookings being previewed (§4). */
    projected?: number;
    config: FatigueConfig;
    size?: 'sm' | 'md';
    showLabel?: boolean;
};

// Safe-zone fatigue is orange (house style) rather than the spec's green.
const zoneText = {
    safe: 'text-gm-orange',
    minor_risk: 'text-zone-minor',
    serious_risk: 'text-zone-serious',
} as const;

function segmentColor(index: number, config: FatigueConfig): string {
    const level = (index + 1) * config.bar_size;

    if (level >= config.serious_at) {
        return 'bg-zone-serious';
    }

    if (level > config.safe_max) {
        return 'bg-zone-minor';
    }

    return 'bg-gm-orange';
}

/**
 * The 10-bar fatigue meter (§6), with an optional projected overlay showing
 * where the current bookings would leave the wrestler.
 */
export function FatigueMeter({
    fatigue,
    projected,
    config,
    size = 'sm',
    showLabel = false,
}: Props) {
    const total = config.max / config.bar_size;
    const filled = Math.floor(fatigue / config.bar_size);
    const after =
        projected === undefined
            ? filled
            : Math.floor(capFatigue(projected, config) / config.bar_size);
    const zone = fatigueZone(projected ?? fatigue, config);

    return (
        <div className="flex items-center gap-2">
            <div
                role="meter"
                aria-label="Fatigue"
                aria-valuemin={0}
                aria-valuemax={config.max}
                aria-valuenow={fatigue}
                aria-valuetext={`${fatigue}%${projected !== undefined ? `, ${capFatigue(projected, config)}% after booking` : ''}`}
                className={cn(
                    'flex',
                    size === 'sm' ? 'h-2.5 gap-[2px]' : 'h-4 gap-[3px]',
                )}
            >
                {Array.from({ length: total }, (_, i) => {
                    const isFilled = i < filled;
                    const isProjected = !isFilled && i < after;

                    return (
                        <span
                            key={i}
                            data-state={
                                isFilled
                                    ? 'filled'
                                    : isProjected
                                      ? 'projected'
                                      : 'empty'
                            }
                            className={cn(
                                size === 'sm' ? 'w-1.5' : 'w-2.5',
                                '-skew-x-12 transition-colors duration-500',
                                isFilled && segmentColor(i, config),
                                isProjected &&
                                    cn(segmentColor(i, config), 'opacity-45'),
                                !isFilled && !isProjected && 'bg-white/8',
                            )}
                            style={
                                isProjected
                                    ? {
                                          backgroundImage:
                                              'repeating-linear-gradient(135deg, rgb(0 0 0 / 0.5) 0 2px, transparent 2px 4px)',
                                      }
                                    : undefined
                            }
                        />
                    );
                })}
            </div>
            {showLabel && (
                <span
                    className={cn('gm-num whitespace-nowrap', zoneText[zone])}
                >
                    {projected !== undefined && projected !== fatigue
                        ? `${capFatigue(projected, config)}%`
                        : `${fatigue}%`}
                    <span className="ml-1 text-xs tracking-wider uppercase opacity-80">
                        {zoneLabel[zone]}
                    </span>
                </span>
            )}
        </div>
    );
}
