import { useSyncExternalStore } from 'react';

export type SaveStatus = 'saving' | 'saved' | null;

/** How long the floppy's check stays up after a save lands. */
export const SAVED_FLASH_MS = 1200;

/*
 * Saving a match or promo re-renders the card page, which remounts the slot
 * editors, so their own state can't carry the "saved" check. This module
 * store outlives the remount.
 */
const statuses = new Map<string, SaveStatus>();
const timers = new Map<string, number>();
const listeners = new Set<() => void>();

function set(key: string, status: SaveStatus): void {
    window.clearTimeout(timers.get(key));
    timers.delete(key);

    if (status === null) {
        statuses.delete(key);
    } else {
        statuses.set(key, status);
    }

    if (status === 'saved') {
        timers.set(
            key,
            window.setTimeout(() => set(key, null), SAVED_FLASH_MS),
        );
    }

    listeners.forEach((listener) => listener());
}

/** The key a card slot's save is tracked under. */
export function slotSaveKey(
    kind: 'match' | 'promo',
    showId: number,
    position: number,
): string {
    return `${kind}:${showId}:${position}`;
}

/** Visit callbacks that track a save under `key`, for `router.put(…)`. */
export function saveVisit(key: string) {
    return {
        onStart: () => set(key, 'saving'),
        onSuccess: () => set(key, 'saved'),
        onError: () => set(key, null),
    };
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function useSaveStatus(key: string): SaveStatus {
    return useSyncExternalStore(subscribe, () => statuses.get(key) ?? null);
}
