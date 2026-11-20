import { useLayoutEffect } from 'react';
import type { KeyboardEvent, RefObject } from 'react';

const RADIOS = '[role="radio"]:not(:disabled):not([aria-disabled="true"])';

/**
 * The ARIA radio pattern's keys for a custom radio group (UI audit,
 * 2026-11-20): the arrows (and Home / End) move to the next enabled radio
 * and select it, wrapping, as native radios do. Put it on the element with
 * `role="radiogroup"`; `useRovingRadios` makes the group one Tab stop.
 */
export function radioKeyDown(e: KeyboardEvent<HTMLElement>): void {
    const radios = [...e.currentTarget.querySelectorAll<HTMLElement>(RADIOS)];
    const at = radios.indexOf(document.activeElement as HTMLElement);

    if (radios.length === 0) {
        return;
    }

    const next =
        e.key === 'ArrowRight' || e.key === 'ArrowDown'
            ? radios[(at + 1) % radios.length]
            : e.key === 'ArrowLeft' || e.key === 'ArrowUp'
              ? radios[(at - 1 + radios.length) % radios.length]
              : e.key === 'Home'
                ? radios[0]
                : e.key === 'End'
                  ? radios[radios.length - 1]
                  : undefined;

    if (next === undefined) {
        return;
    }

    e.preventDefault();
    next.focus();
    next.click();
}

/**
 * One Tab stop per radio group inside `root` (and `root` itself, if it is
 * one): the checked radio, else the first enabled one. Runs after every
 * render, so it follows the selection.
 */
export function useRovingRadios(root: RefObject<HTMLElement | null>): void {
    useLayoutEffect(() => {
        const el = root.current;

        if (!el) {
            return;
        }

        const groups = [
            ...(el.matches('[role="radiogroup"]') ? [el] : []),
            ...el.querySelectorAll<HTMLElement>('[role="radiogroup"]'),
        ];

        for (const group of groups) {
            const radios = [
                ...group.querySelectorAll<HTMLElement>('[role="radio"]'),
            ];
            const enabled = [...group.querySelectorAll<HTMLElement>(RADIOS)];
            const stop =
                enabled.find(
                    (r) => r.getAttribute('aria-checked') === 'true',
                ) ?? enabled[0];

            for (const radio of radios) {
                radio.tabIndex = radio === stop ? 0 : -1;
            }
        }
    });
}
