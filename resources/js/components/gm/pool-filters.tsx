import type { PoolFilter } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { RosterType } from '@/types/enums';
import { RosterTypeLabels } from '@/types/enums';

const typeLabel = (type: RosterType | 'all') =>
    type === 'all' ? 'All' : `${RosterTypeLabels[type]}s`;

function Chip({
    active,
    disabled,
    onClick,
    children,
    pressed,
}: {
    active: boolean;
    disabled?: boolean;
    onClick: () => void;
    children: string;
    pressed?: boolean;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            aria-pressed={pressed}
            className={cn(
                'inline-flex min-h-11 items-center px-2 py-1 font-condensed text-xs font-semibold tracking-wider uppercase sm:min-h-0',
                active ? 'bg-brand text-white' : 'text-ink-dim hover:text-ink',
            )}
        >
            {children}
        </button>
    );
}

/**
 * Filters for a wrestler pool (draft room, Free Agency, rosters): roster
 * type, wrestlers with match specialties, and a name search.
 */
export function PoolFilters({
    value,
    onChange,
    types,
    disabled = false,
}: {
    value: PoolFilter;
    onChange: (filter: PoolFilter) => void;
    types: ReadonlyArray<RosterType | 'all'>;
    disabled?: boolean;
}) {
    const set = (patch: Partial<PoolFilter>) =>
        onChange({ ...value, ...patch });

    return (
        <div className="flex flex-wrap items-center gap-2">
            {types.map((type) => (
                <Chip
                    key={type}
                    active={value.type === type}
                    disabled={disabled}
                    onClick={() => set({ type })}
                >
                    {typeLabel(type)}
                </Chip>
            ))}
            <span className="h-4 w-px bg-white/15" aria-hidden />
            <Chip
                active={value.specialists}
                pressed={value.specialists}
                disabled={disabled}
                onClick={() => set({ specialists: !value.specialists })}
            >
                Specialists
            </Chip>
            <input
                type="search"
                className="gm-input min-h-11 w-40 py-1 text-sm sm:min-h-0"
                placeholder="Search…"
                aria-label="Search superstars"
                value={value.search}
                disabled={disabled}
                onChange={(e) => set({ search: e.target.value })}
            />
        </div>
    );
}
