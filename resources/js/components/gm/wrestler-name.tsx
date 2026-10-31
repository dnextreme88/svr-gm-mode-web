import { Fragment } from 'react';
import { cn } from '@/lib/utils';
import type { RosterType, WeightClass } from '@/types/enums';

export type Named = {
    name: string | null;
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
}: {
    wrestler: Named;
    className?: string;
}) {
    const tone = nameTone(wrestler);

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
}: {
    names: Named[];
    separator?: string;
}) {
    return (
        <>
            {names.map((n, i) => (
                <Fragment key={i}>
                    {i > 0 && separator}
                    <WrestlerName wrestler={n} />
                </Fragment>
            ))}
        </>
    );
}
