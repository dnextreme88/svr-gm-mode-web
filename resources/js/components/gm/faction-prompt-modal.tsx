import { router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { WrestlerName } from '@/components/gm/wrestler-name';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import gm from '@/routes/gm';
import { FactionType, FactionTypeLabels } from '@/types/enums';
import type { FactionPrompt } from '@/types/gm';

const TYPES = Object.values(FactionType);

/**
 * Factions (house rule): the player's 2 or 3 superstars won together for
 * the 3rd time. Step 1 names them and picks tag team or stable; step 2
 * confirms. "Not now" asks again after their next win together.
 */
export function FactionPromptModal({
    saveId,
    prompt,
    brand,
}: {
    saveId: number;
    prompt: FactionPrompt;
    brand: string;
}) {
    const [name, setName] = useState('');
    const [type, setType] = useState<FactionType>(prompt.default_type);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const trimmed = name.trim();
    const names = prompt.members.map((m) => m.name);

    const cycle = (step: number) =>
        setType(
            TYPES[(TYPES.indexOf(type) + step + TYPES.length) % TYPES.length],
        );

    const post = (url: string, data: Record<string, string> = {}) => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setConfirming(false);
            },
        });
    };

    return (
        <>
            <Dialog open={!confirming}>
                <DialogContent
                    data-brand-tint={brand}
                    className="gm-panel rounded-none border-white/10 bg-arena-2 font-gm text-ink [&>button]:hidden"
                    onEscapeKeyDown={(e) => e.preventDefault()}
                    onInteractOutside={(e) => e.preventDefault()}
                >
                    <DialogTitle className="gm-heading text-2xl">
                        A new faction?
                    </DialogTitle>
                    <DialogDescription className="text-ink-dim">
                        {names.length === 2
                            ? 'These two have'
                            : 'These three have'}{' '}
                        won {prompt.wins} matches together
                        {prompt.show ? `, the latest on ${prompt.show}` : ''}.
                        Give them a name and they'll be billed as one from now
                        on.
                    </DialogDescription>

                    <p
                        className="text-lg font-semibold"
                        data-testid="faction-prompt-members"
                    >
                        {prompt.members.map((m, i) => (
                            <Fragment key={m.id}>
                                {i > 0 && ' & '}
                                <WrestlerName wrestler={m} />
                            </Fragment>
                        ))}
                    </p>

                    <label className="block">
                        <span className="gm-label">Faction name</span>
                        <input
                            className="gm-input mt-1 w-full"
                            value={name}
                            maxLength={prompt.name_max_length}
                            placeholder="e.g. The Dream Team"
                            onChange={(e) => setName(e.target.value)}
                            autoFocus
                        />
                    </label>

                    <div>
                        <span className="gm-label">Type</span>
                        <div className="mt-1 flex items-center gap-2">
                            <button
                                type="button"
                                className="gm-btn gm-btn-ghost gm-btn-sm"
                                aria-label="Previous type"
                                onClick={() => cycle(-1)}
                            >
                                ‹
                            </button>
                            <span
                                className="min-w-24 text-center font-condensed tracking-wider uppercase"
                                data-testid="faction-prompt-type"
                            >
                                {FactionTypeLabels[type]}
                            </span>
                            <button
                                type="button"
                                className="gm-btn gm-btn-ghost gm-btn-sm"
                                aria-label="Next type"
                                onClick={() => cycle(1)}
                            >
                                ›
                            </button>
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <button
                            type="button"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            disabled={busy}
                            onClick={() =>
                                post(
                                    gm.factions.prompts.defer.url({
                                        save: saveId,
                                        factionPrompt: prompt.id,
                                    }),
                                )
                            }
                        >
                            Not now
                        </button>
                        <button
                            type="button"
                            className="gm-btn gm-btn-sm"
                            disabled={busy || trimmed === ''}
                            onClick={() => setConfirming(true)}
                        >
                            Form faction
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirming}
                title={`Form ${trimmed}?`}
                message={`${names.join(' & ')} will be known as ${trimmed}, a ${FactionTypeLabels[type].toLowerCase()}. It shows on the Factions page and in the news.`}
                confirmLabel="Form faction"
                cancelLabel="Back"
                onCancel={() => setConfirming(false)}
                onConfirm={() =>
                    post(
                        gm.factions.prompts.form.url({
                            save: saveId,
                            factionPrompt: prompt.id,
                        }),
                        { name: trimmed, type },
                    )
                }
            />
        </>
    );
}
