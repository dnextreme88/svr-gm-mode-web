import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { emptyPoolFilter } from '@/lib/gm';
import type { PoolFilter } from '@/lib/gm';

/**
 * Inertia's page URL, or nothing outside an Inertia page (a component
 * rendered on its own, as in a test): `usePage` throws there.
 */
function usePageUrl(): string | undefined {
    try {
        return usePage().url as string | undefined;
    } catch {
        return undefined;
    }
}

/**
 * A query parameter of the page's URL. The page is server-rendered, so the
 * server reads Inertia's page URL and the browser the same URL from
 * `location`: both render the same thing and hydration matches.
 */
function read(key: string, pageUrl: string | undefined): string | null {
    const search =
        typeof window !== 'undefined'
            ? window.location.search
            : pageUrl
              ? new URL(pageUrl, 'http://localhost').search
              : '';

    return new URLSearchParams(search).get(key);
}

/**
 * A piece of page state kept in the URL's query string (UI audit,
 * 2026-11-20): filters, sorts, tabs and the week or month on screen, so a
 * refresh, Back or a shared link lands on the same view. It writes with
 * `history.replaceState` (keeping Inertia's history state), so changing it
 * never visits the server or adds a history entry. The fallback is left out
 * of the URL.
 */
export function useUrlParam(
    key: string,
    fallback: string,
): [string, (value: string) => void] {
    const pageUrl = usePageUrl();
    const [value, setValue] = useState<string>(
        () => read(key, pageUrl) ?? fallback,
    );

    const update = useCallback(
        (next: string) => {
            setValue(next);

            if (typeof window === 'undefined') {
                return;
            }

            const url = new URL(window.location.href);

            if (next === fallback || next === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, next);
            }

            if (url.href !== window.location.href) {
                window.history.replaceState(window.history.state, '', url);
            }
        },
        [key, fallback],
    );

    return [value, update];
}

/**
 * A wrestler pool's filters in the URL (`?type=`, `?specialists=1`, `?q=`),
 * for the draft room, Free Agency and the rosters.
 */
export function usePoolFilterUrl(): [PoolFilter, (filter: PoolFilter) => void] {
    const [type, setType] = useUrlParam('type', emptyPoolFilter.type);
    const [specialists, setSpecialists] = useUrlParam('specialists', '');
    const [search, setSearch] = useUrlParam('q', '');

    return [
        {
            type: type as PoolFilter['type'],
            specialists: specialists === '1',
            search,
        },
        useCallback(
            (filter: PoolFilter) => {
                setType(filter.type);
                setSpecialists(filter.specialists ? '1' : '');
                setSearch(filter.search);
            },
            [setType, setSpecialists, setSearch],
        ),
    ];
}

/**
 * A table's sort in the URL (`?sort=fatigue:desc`). `keys` are the columns
 * it can sort by; anything else in the URL is ignored. `param` names it when
 * a page has more than one sortable table, and `fallback` is the sort with
 * nothing in the URL (left out of the URL).
 */
export function useSortUrl<K extends string>(
    keys: readonly K[],
    param = 'sort',
    fallback: { key: K; dir: 'asc' | 'desc' } | null = null,
): [
    { key: K; dir: 'asc' | 'desc' } | null,
    (
        next:
            | { key: K; dir: 'asc' | 'desc' }
            | null
            | ((
                  current: { key: K; dir: 'asc' | 'desc' } | null,
              ) => { key: K; dir: 'asc' | 'desc' } | null),
    ) => void,
] {
    const [raw, setRaw] = useUrlParam(
        param,
        fallback ? `${fallback.key}:${fallback.dir}` : '',
    );
    const [key, dir] = raw.split(':');
    const sort =
        keys.includes(key as K) && (dir === 'asc' || dir === 'desc')
            ? { key: key as K, dir: dir as 'asc' | 'desc' }
            : null;

    return [
        sort,
        (next) => {
            const value = typeof next === 'function' ? next(sort) : next;
            setRaw(value ? `${value.key}:${value.dir}` : '');
        },
    ];
}

/** `useUrlParam` for a whole number (a week, a month index). */
export function useUrlNumber(
    key: string,
    fallback: number,
): [number, (value: number) => void] {
    const [raw, setRaw] = useUrlParam(key, String(fallback));
    const parsed = Number.parseInt(raw, 10);

    return [
        Number.isFinite(parsed) ? parsed : fallback,
        useCallback((n: number) => setRaw(String(n)), [setRaw]),
    ];
}
