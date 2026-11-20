import { Fragment } from 'react';
import { SuperstarPortrait } from '@/components/gm/superstar-portrait';
import { cn } from '@/lib/utils';
import type { RosterType, WeightClass } from '@/types/enums';

export type Named = {
    name: string | null;
    /** The catalog slug, for their portrait (2026-11-20). */
    slug?: string | null;
    roster_type: RosterType | null;
    weight_class: WeightClass | null;
};

/**
 * SvR 2006 colour coding: Cruiserweights and Light Heavyweights in blue,
 * Divas in red, everyone else in the default ink.
 */
export function nameTone(w: Omit<Named, 'name'>): 'diva' | 'cruiser' | null {
    if (w.roster_type === 'diva') {
        return 'diva';
    }

    if (
        w.weight_class === 'cruiserweight' ||
        w.weight_class === 'light_heavyweight'
    ) {
        return 'cruiser';
    }

    return null;
}

export function WrestlerName({
    wrestler,
    className,
    portrait,
}: {
    wrestler: Named;
    className?: string;
    /**
     * Their portrait before the name (2026-11-20): sm 24px inline, md 32px
     * in rows, lg 48px for featured names. Left out in the pickers.
     */
    portrait?: 'sm' | 'md' | 'lg';
}) {
    const tone = nameTone(wrestler);

    if (portrait) {
        return (
            <span className="inline-flex max-w-full min-w-0 items-center gap-2 align-middle">
                <SuperstarPortrait slug={wrestler.slug} size={portrait} />
                <WrestlerName
                    wrestler={wrestler}
                    className={cn('min-w-0', className)}
                />
            </span>
        );
    }

    return (
        <span
            data-tone={tone ?? undefined}
            className={cn(
                tone === 'diva' && 'gm-name-diva',
                tone === 'cruiser' && 'gm-name-cruiser',
                className,
            )}
        >
            {wrestler.name}
            {/* The colour says it on screen; screen readers hear it (UI
                audit, 2026-11-20). */}
            {tone && (
                <span className="sr-only">
                    {tone === 'diva' ? ' (Diva)' : ' (Cruiserweight)'}
                </span>
            )}
        </span>
    );
}

/**
 * Several names joined by `separator`, each colour-coded. A faction or team
 * name comes as one part with no roster type, so it stays uncoloured.
 */
export function WrestlerNames({
    names,
    separator = ' & ',
    portrait,
}: {
    names: Named[];
    separator?: string;
    /** Each superstar's portrait before their name (not a faction's). */
    portrait?: 'sm' | 'md' | 'lg';
}) {
    return (
        <>
            {names.map((n, i) => (
                <Fragment key={i}>
                    {/* Names with a portrait sit centred on the line
                        (align-middle), so the separator does too, or the
                        "&" between them reads high (2026-11-21). */}
                    {i > 0 &&
                        (portrait ? (
                            <span
                                data-testid="names-separator"
                                className="align-middle whitespace-pre"
                            >
                                {separator}
                            </span>
                        ) : (
                            separator
                        ))}
                    <WrestlerName
                        wrestler={n}
                        portrait={n.roster_type ? portrait : undefined}
                    />
                </Fragment>
            ))}
        </>
    );
}
