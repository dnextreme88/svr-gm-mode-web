import { useState } from 'react';
import { BusyLabel } from '@/components/gm/busy-label';
import { Dice } from '@/components/gm/dice';
import { requestJson } from '@/lib/http';

/**
 * Randomize for a Qualifying Match (house rule, 2026-11-17): fills its three
 * pickers with eligible men (never Divas) the match leaves at 70% fatigue or
 * less. Nothing is saved until the player presses Save.
 */
export function QualifierRandomize({
    url,
    onPick,
}: {
    url: string;
    onPick: (ids: number[]) => void;
}) {
    const [roll, setRoll] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const randomize = async () => {
        setBusy(true);
        setError(null);

        try {
            const { qualifier } = await requestJson<{ qualifier: number[] }>(
                'POST',
                url,
                { roll },
            );

            if (qualifier.length === 0) {
                setError('Not enough superstars to fill it.');
            } else {
                onPick(qualifier);
            }

            setRoll((r) => r + 1);
        } catch {
            setError('Couldn’t randomize. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="gm-btn gm-btn-ghost gm-btn-sm"
                disabled={busy}
                aria-busy={busy || undefined}
                onClick={randomize}
            >
                <BusyLabel busy={busy} busyText="Rolling…">
                    <Dice face={5} rolling={false} />
                    Randomize
                </BusyLabel>
            </button>
            {error && (
                <span role="alert" className="text-xs text-red-400">
                    {error}
                </span>
            )}
        </>
    );
}
