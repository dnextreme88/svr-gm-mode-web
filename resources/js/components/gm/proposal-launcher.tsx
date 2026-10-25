import { useState } from 'react';
import { Dice } from '@/components/gm/dice';
import { ProposalModal } from '@/components/gm/proposal-modal';
import type { ProposalView } from '@/components/gm/proposal-modal';
import { HttpError } from '@/lib/http';
import { cn } from '@/lib/utils';

export type ProposalResult = {
    proposals: ProposalView[];
    unfilled_slots: number[];
    total_cost: number;
    deficit_warning: boolean;
};

type Props = {
    label: string;
    mode: 'randomize' | 'preset';
    fetchProposal: (roll: number) => Promise<ProposalResult>;
    onConfirm: (proposals: ProposalView[]) => Promise<void> | void;
    titleNames?: Record<number, string>;
    disabled?: boolean;
    className?: string;
};

/**
 * A Randomize / Preset button and its preview modal (§24). Re-roll asks the
 * server for a new proposal; a 422 shows its message and changes nothing.
 */
export function ProposalLauncher({
    label,
    mode,
    fetchProposal,
    onConfirm,
    titleNames,
    disabled,
    className,
}: Props) {
    const [open, setOpen] = useState(false);
    const [roll, setRoll] = useState(0);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<ProposalResult | null>(null);

    const load = async (nextRoll: number) => {
        setRoll(nextRoll);
        setLoading(true);
        setError(null);

        try {
            setResult(await fetchProposal(nextRoll));
        } catch (e) {
            setResult(null);
            setError(
                e instanceof HttpError || e instanceof Error
                    ? e.message
                    : 'Something went wrong.',
            );
        } finally {
            setLoading(false);
        }
    };

    const close = () => {
        setOpen(false);
        setResult(null);
        setError(null);
    };

    return (
        <>
            <button
                type="button"
                className={cn('gm-btn gm-btn-ghost gm-btn-sm', className)}
                disabled={disabled}
                onClick={() => {
                    setOpen(true);
                    void load(roll);
                }}
            >
                <Dice face={mode === 'preset' ? 6 : 5} rolling={loading} />
                {label}
            </button>
            <ProposalModal
                open={open}
                mode={mode}
                roll={roll}
                loading={loading}
                error={error}
                proposals={result?.proposals ?? []}
                unfilledSlots={result?.unfilled_slots ?? []}
                totalCost={result?.total_cost ?? 0}
                deficitWarning={result?.deficit_warning ?? false}
                titleNames={titleNames}
                onCancel={close}
                onReroll={() => void load(roll + 1)}
                onConfirm={async () => {
                    if (!result) {
                        return;
                    }

                    try {
                        await onConfirm(result.proposals);
                        close();
                    } catch (e) {
                        setError(
                            e instanceof Error
                                ? e.message
                                : 'Something went wrong.',
                        );
                    }
                }}
            />
        </>
    );
}
