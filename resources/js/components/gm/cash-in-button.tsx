import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { cn } from '@/lib/utils';
import type { CardSlot, TitleContext } from '@/types/gm';

/**
 * Cash in the Money in the Bank briefcase on this card, before the show
 * airs (house rule): pick where the cash-in match goes, before any match
 * or after the Main Event. The matches from there on move down one spot.
 */
export function CashInButton({
    briefcase,
    slots,
    url,
}: {
    briefcase: NonNullable<TitleContext['briefcase']>;
    slots: CardSlot[];
    url: string;
}) {
    const [open, setOpen] = useState(false);
    // The card's own matches, in order; the last is the Main Event.
    const matches = slots
        .filter((s) => s.fixed_kind !== 'mitb_cash_in')
        .map((s) => s.position)
        .sort((a, b) => a - b);
    const main = matches.at(-1);
    const after = (main ?? 0) + 1;
    const [position, setPosition] = useState(after);
    const options = [
        ...matches.map((p) => ({
            position: p,
            label: p === main ? 'Before the Main Event' : `Before Match ${p}`,
        })),
        { position: after, label: 'After the Main Event' },
    ];

    return (
        <>
            <button
                type="button"
                className="gm-btn gm-btn-gold gm-btn-sm"
                title={`Expires week ${briefcase.expires_week}`}
                onClick={() => {
                    setPosition(after);
                    setOpen(true);
                }}
            >
                💼 Cash in: {briefcase.holder}
            </button>
            <ConfirmDialog
                open={open}
                title="Cash in Money in the Bank?"
                message={
                    <div>
                        <p>
                            {briefcase.holder} cashes in on the world champion
                            on this card. Where does the match go?
                        </p>
                        <div
                            role="radiogroup"
                            aria-label="Where the cash-in goes"
                            className="mt-3 space-y-1"
                        >
                            {options.map((o) => (
                                <label
                                    key={o.position}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2 border px-3 py-2 text-ink',
                                        position === o.position
                                            ? 'border-gold/70 bg-gold/10'
                                            : 'border-white/10 hover:bg-white/6',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="cash-in-position"
                                        checked={position === o.position}
                                        onChange={() => setPosition(o.position)}
                                        className="accent-[var(--color-gold)]"
                                    />
                                    {o.label}
                                </label>
                            ))}
                        </div>
                    </div>
                }
                confirmLabel="Cash in"
                onCancel={() => setOpen(false)}
                onConfirm={() => {
                    setOpen(false);
                    router.post(url, { position }, { preserveScroll: true });
                }}
            />
        </>
    );
}
