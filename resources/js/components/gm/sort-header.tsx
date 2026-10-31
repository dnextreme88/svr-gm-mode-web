import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type SortState<K extends string> = {
    key: K;
    dir: 'asc' | 'desc';
} | null;

/** The next sort after clicking a column: ascending first, then reversed. */
export function nextSort<K extends string>(
    sort: SortState<K>,
    key: K,
): SortState<K> {
    return sort?.key === key
        ? { key, dir: sort.dir === 'asc' ? 'desc' : 'asc' }
        : { key, dir: 'asc' };
}

/** The `aria-sort` value for a column header. */
export function ariaSort<K extends string>(
    sort: SortState<K>,
    key: K,
): 'ascending' | 'descending' | 'none' {
    if (sort?.key !== key) {
        return 'none';
    }

    return sort.dir === 'asc' ? 'ascending' : 'descending';
}

/** A sortable column header's button, with an arrow for its direction. */
export function SortHeader({
    label,
    active,
    onClick,
    right = false,
}: {
    label: ReactNode;
    active: 'asc' | 'desc' | null;
    onClick: () => void;
    /** Right-aligned columns put the arrow before the label. */
    right?: boolean;
}) {
    const Icon =
        active === 'asc'
            ? ArrowUp
            : active === 'desc'
              ? ArrowDown
              : ArrowUpDown;

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'gm-row-link inline-flex items-center gap-1 uppercase hover:text-ink',
                right && 'flex-row-reverse',
                active && 'text-ink',
            )}
        >
            {label}
            <Icon
                aria-hidden
                className={cn('size-3.5', !active && 'opacity-50')}
            />
        </button>
    );
}
