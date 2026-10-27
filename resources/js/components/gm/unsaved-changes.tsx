import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes-guard';

type Report = (key: string, dirty: boolean) => void;

const UnsavedChangesContext = createContext<Report | null>(null);

/**
 * The card page's unsaved matches and promos. Each slot reports itself with
 * `useReportUnsaved`; while any is unsaved, leaving the page asks first
 * ("Match 2 and Promo 1 have unsaved changes…") and a refresh gets the
 * browser's own prompt.
 */
export function UnsavedChangesProvider({ children }: { children: ReactNode }) {
    const [unsaved, setUnsaved] = useState<string[]>([]);
    const guard = useUnsavedChangesGuard(unsaved.length > 0);

    const report = useCallback<Report>((key, dirty) => {
        setUnsaved((current) => {
            const has = current.includes(key);

            if (dirty === has) {
                return current;
            }

            return dirty ? [...current, key] : current.filter((k) => k !== key);
        });
    }, []);

    return (
        <UnsavedChangesContext.Provider value={report}>
            {children}
            <ConfirmDialog
                open={guard.pending !== null && unsaved.length > 0}
                title="Unsaved changes"
                message={`${unsavedList(unsaved)} ${unsaved.length === 1 ? 'has' : 'have'} unsaved changes. Leave this page and discard them?`}
                confirmLabel="Discard and leave"
                cancelLabel="Stay"
                danger
                onCancel={guard.stay}
                onConfirm={guard.proceed}
            />
        </UnsavedChangesContext.Provider>
    );
}

/**
 * Tell the page whether a slot has unsaved changes. `key` is the slot's
 * name as shown in the dialog: "Match 2", "Promo 1". Does nothing outside
 * an `UnsavedChangesProvider`.
 */
export function useReportUnsaved(key: string, dirty: boolean) {
    const report = useContext(UnsavedChangesContext);

    useEffect(() => {
        report?.(key, dirty);
    }, [report, key, dirty]);

    // Unsaved work goes with the slot when it unmounts.
    useEffect(() => () => report?.(key, false), [report, key]);
}

/**
 * "Match 2", "Match 2 and Promo 1", "Matches 1 and 3 and Promo 2": matches
 * first, then promos, each in order.
 */
export function unsavedList(keys: string[]): string {
    const numbers = (kind: string) =>
        keys
            .filter((k) => k.startsWith(`${kind} `))
            .map((k) => Number(k.slice(kind.length + 1)))
            .sort((a, b) => a - b);

    const group = (kind: string, plural: string) => {
        const n = numbers(kind);

        if (n.length === 0) {
            return null;
        }

        return n.length === 1
            ? `${kind} ${n[0]}`
            : `${plural} ${n.slice(0, -1).join(', ')} and ${n[n.length - 1]}`;
    };

    return [group('Match', 'Matches'), group('Promo', 'Promos')]
        .filter((g): g is string => g !== null)
        .join(' and ');
}
