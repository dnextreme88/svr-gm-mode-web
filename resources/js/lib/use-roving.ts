import { useCallback, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';

/**
 * One Tab stop for a chart's points (UI audit, 2026-11-20): Tab reaches the
 * chart once, then the arrow keys (and Home / End) move between its points
 * in `keys` order. `keys` are stable ids, one per point.
 */
export function useRoving(keys: string[]) {
    const [current, setCurrent] = useState<string | null>(null);
    const nodes = useRef(new Map<string, SVGElement | HTMLElement>());
    const stop = current !== null && keys.includes(current) ? current : keys[0];

    const move = useCallback(
        (from: string, e: KeyboardEvent) => {
            const at = keys.indexOf(from);
            const to =
                e.key === 'ArrowRight' || e.key === 'ArrowDown'
                    ? keys[Math.min(keys.length - 1, at + 1)]
                    : e.key === 'ArrowLeft' || e.key === 'ArrowUp'
                      ? keys[Math.max(0, at - 1)]
                      : e.key === 'Home'
                        ? keys[0]
                        : e.key === 'End'
                          ? keys[keys.length - 1]
                          : undefined;

            if (to !== undefined) {
                e.preventDefault();
                setCurrent(to);
                nodes.current.get(to)?.focus();
            }
        },
        [keys],
    );

    return {
        props: (key: string) => ({
            tabIndex: key === stop ? 0 : -1,
            ref: (el: SVGElement | HTMLElement | null) => {
                if (el) {
                    nodes.current.set(key, el);
                } else {
                    nodes.current.delete(key);
                }
            },
            onKeyDown: (e: KeyboardEvent) => move(key, e),
        }),
        focused: (key: string) => setCurrent(key),
    };
}
