import { Head, Link, router, useForm } from '@inertiajs/react';
import { BookOpen, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { FirstPickReel } from '@/components/gm/first-pick-reel';
import {
    loadingOverlayDelay,
    LoadingOverlay,
} from '@/components/gm/loading-overlay';
import { EmptyState, Panel } from '@/components/gm/panel';
import { formatMoney } from '@/lib/gm';
import { HttpError, requestJson } from '@/lib/http';
import { useBusy } from '@/lib/use-busy';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand, DraftMode, SaveStage } from '@/types/enums';
import { DraftModeLabels, SaveStageLabels } from '@/types/enums';

type SaveSlot = {
    id: number;
    name: string;
    player_brand: Brand;
    stage: SaveStage;
    season: number;
    season_week: number;
    month: string;
    budget: number | null;
    updated_at: string | null;
};

type Perk = { text: string; tone?: 'diva' | 'cruiser' };

/*
 * Line 1: the brand's open championships. Line 2: its colour-coded title,
 * in the same colour the roster uses (Divas red, Cruiserweights blue).
 * Line 3: the match exclusive to one of its own PPVs.
 */
const brandPitch: Record<Brand, { tagline: string; perks: Perk[] }> = {
    raw: {
        tagline: 'Monday nights. Red ropes. No mercy.',
        perks: [
            { text: 'WWE, Intercontinental & World Tag Team titles' },
            { text: "Women's Championship", tone: 'diva' },
            { text: "Elimination Chamber at New Year's Revolution" },
        ],
    },
    smackdown: {
        tagline: 'Friday nights. Blue steel. Fast and loose.',
        perks: [
            { text: 'World Heavyweight, United States & WWE Tag Team titles' },
            { text: 'Cruiserweight Championship', tone: 'cruiser' },
            { text: 'Buried Alive at No Mercy' },
        ],
    },
};

/**
 * A button that deletes a career after a GM-styled confirmation, busy until
 * the delete has gone through (UI audit, 2026-11-12).
 */
function DeleteSaveButton({
    save,
    title,
    message,
    confirmLabel,
    className,
    label,
    children,
}: {
    save: SaveSlot;
    title: string;
    message: string;
    confirmLabel: string;
    className: string;
    label?: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const { busy, track } = useBusy();

    return (
        <>
            <button
                type="button"
                className={className}
                aria-label={label}
                onClick={() => setOpen(true)}
            >
                {children}
            </button>
            <ConfirmDialog
                open={open}
                danger
                focusCancel
                title={title}
                message={message}
                confirmLabel={confirmLabel}
                busy={busy !== null}
                busyText="Deleting…"
                onCancel={() => setOpen(false)}
                onConfirm={() =>
                    router.delete(gm.saves.destroy.url(save.id), {
                        preserveScroll: true,
                        ...track('delete'),
                        onSuccess: () => setOpen(false),
                    })
                }
            />
        </>
    );
}

/**
 * The career in progress that was never saved. Starting a new game
 * discards it.
 */
function UnsavedCareer({ save }: { save: SaveSlot }) {
    return (
        <div
            data-brand-tint={save.player_brand}
            data-testid="unsaved-career"
            className="mb-8 flex flex-wrap items-center gap-4 border border-dashed border-white/15 bg-black/30 px-4 py-3"
        >
            <BrandBug brand={save.player_brand} size="sm" />
            <div className="min-w-0 flex-1">
                <div className="gm-label">Unsaved career in progress</div>
                <div className="truncate font-semibold">
                    {save.name}
                    <span className="ml-2 font-condensed text-xs tracking-wider text-ink-dim uppercase">
                        {SaveStageLabels[save.stage]} · Season {save.season},
                        week {save.season_week}
                    </span>
                </div>
                <p className="text-xs text-ink-faint">
                    Save it from the top bar to keep it. Starting a new game
                    discards it.
                </p>
            </div>
            <Link
                href={gm.saves.show.url(save.id)}
                className="gm-btn gm-btn-sm"
            >
                Resume
            </Link>
            <DeleteSaveButton
                save={save}
                className="gm-btn gm-btn-sm gm-btn-ghost"
                title="Discard this career?"
                message={`Discard "${save.name}"? It was never saved.`}
                confirmLabel="Discard"
            >
                Discard
            </DeleteSaveButton>
        </div>
    );
}

const draftModePitch: Record<DraftMode, string> = {
    manual: 'Manually pick your own superstars and alternate picks with the rival GM.',
    wwe_roster:
        'Both brands start with a preset WWE roster from November 2005.',
};

function draftRules(startingBudget: number, mode: DraftMode): string[] {
    if (mode === 'wwe_roster') {
        return [
            'Each brand starts with a preset 30-man WWE roster of November 2005',
            `Each show starts with a ${formatMoney(startingBudget)} budget; the signing fees come out of it`,
        ];
    }

    return [
        'Initial draft pick is chosen at random',
        `Each show starts with a ${formatMoney(startingBudget)} budget`,
        'You are not required to draft and fill up 30 slots',
        'Legends cannot be drafted on the initial draft',
        'Recommended roster size: 15-30',
    ];
}

type Started = { next_url: string; first_brand: Brand };

export default function Saves({
    saves,
    unsaved,
    starting_budget,
}: {
    saves: SaveSlot[];
    unsaved: SaveSlot | null;
    starting_budget: number;
}) {
    const form = useForm<{
        name: string;
        brand: Brand;
        draft_mode: DraftMode;
    }>({
        name: `My GM Career ${saves.length + 1}`,
        brand: 'raw',
        draft_mode: 'manual',
    });
    const usesWweRoster = form.data.draft_mode === 'wwe_roster';
    // The reel opens as soon as Start is pressed; the result lands when the
    // server has created the career and drawn the order.
    const [reel, setReel] = useState<{ result: Started | null } | null>(null);
    // A one-off button (house rule, 2026-11-12): the loading overlay covers
    // the page for its pause before the next screen opens. On a manual draft
    // it follows the who-picks-first reel; with the WWE rosters it shows
    // while the career is created.
    const [loading, setLoading] = useState(false);
    const overlayPause = () =>
        new Promise<void>((resolve) =>
            window.setTimeout(resolve, loadingOverlayDelay('new-game')),
        );

    // The reel has landed: the overlay, then the draft room.
    const openDraft = async (nextUrl: string) => {
        setLoading(true);
        await overlayPause();
        router.visit(nextUrl);
    };

    const start = async () => {
        form.clearErrors();

        if (usesWweRoster) {
            setLoading(true);
        } else {
            setReel({ result: null });
        }

        const pause = usesWweRoster ? overlayPause() : Promise.resolve();

        try {
            const started = await requestJson<Started>(
                'POST',
                gm.saves.store.url(),
                form.data,
            );
            await pause;

            // There's no draft order to reveal when the rosters are signed:
            // the overlay stays up until the next screen opens.
            if (usesWweRoster) {
                router.visit(started.next_url);
            } else {
                setReel({ result: started });
            }
        } catch (error) {
            setLoading(false);
            setReel(null);
            form.setError(
                'name',
                error instanceof HttpError
                    ? error.message
                    : 'Could not start the game.',
            );
        }
    };

    return (
        <>
            <Head title="GM Mode" />

            <div className="mb-10 animate-gm-rise text-center">
                <div className="gm-label mb-3 tracking-[0.4em]">
                    Smackdown vs Raw 2006
                </div>
                <h1 className="gm-heading text-6xl font-bold md:text-8xl">
                    General Manager
                </h1>
                <p className="mt-3 text-ink-dim">
                    Draft a roster, book the shows, protect the gold. One season
                    ends at WrestleMania.
                </p>
            </div>

            {loading && (
                <LoadingOverlay
                    testId="starting-game"
                    text={
                        usesWweRoster
                            ? 'Signing the WWE rosters. Please wait...'
                            : 'Setting up the draft. Please wait...'
                    }
                />
            )}

            {reel && !usesWweRoster && !loading && (
                <FirstPickReel
                    result={reel.result?.first_brand ?? null}
                    onDone={() =>
                        reel.result && void openDraft(reel.result.next_url)
                    }
                />
            )}

            {unsaved && <UnsavedCareer save={unsaved} />}

            <div className="grid gap-8 lg:grid-cols-[1.4fr_1fr] lg:items-start">
                <Panel title="New game">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            void start();
                        }}
                        className="space-y-6"
                    >
                        <fieldset>
                            <legend className="gm-label mb-3">
                                Pick your brand
                            </legend>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {(['raw', 'smackdown'] as const).map(
                                    (brand) => {
                                        const selected =
                                            form.data.brand === brand;

                                        return (
                                            <label
                                                key={brand}
                                                data-brand-tint={brand}
                                                className={cn(
                                                    'group relative block cursor-pointer overflow-hidden border p-5 transition-all',
                                                    selected
                                                        ? 'border-brand bg-brand/10 shadow-[0_0_40px_-12px_var(--gm-brand)]'
                                                        : 'border-white/10 bg-black/30 hover:border-white/25',
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    name="brand"
                                                    value={brand}
                                                    checked={selected}
                                                    onChange={() =>
                                                        form.setData(
                                                            'brand',
                                                            brand,
                                                        )
                                                    }
                                                    className="sr-only"
                                                />
                                                <BrandBug
                                                    brand={brand}
                                                    size="lg"
                                                />
                                                <p className="mt-3 font-condensed text-sm tracking-wide text-ink-dim italic">
                                                    {brandPitch[brand].tagline}
                                                </p>
                                                <ul className="mt-3 space-y-1 text-sm text-ink">
                                                    {brandPitch[
                                                        brand
                                                    ].perks.map((perk) => (
                                                        <li
                                                            key={perk.text}
                                                            className="flex gap-2"
                                                        >
                                                            <span className="text-brand-hot">
                                                                ▸
                                                            </span>
                                                            <span
                                                                className={cn(
                                                                    perk.tone ===
                                                                        'diva' &&
                                                                        'gm-name-diva font-semibold',
                                                                    perk.tone ===
                                                                        'cruiser' &&
                                                                        'gm-name-cruiser font-semibold',
                                                                )}
                                                            >
                                                                {perk.text}
                                                            </span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </label>
                                        );
                                    },
                                )}
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend className="gm-label mb-3">Rosters</legend>
                            <div
                                className="grid gap-4 sm:grid-cols-2"
                                data-brand-tint={form.data.brand}
                            >
                                {(['manual', 'wwe_roster'] as const).map(
                                    (mode) => {
                                        const selected =
                                            form.data.draft_mode === mode;

                                        return (
                                            <label
                                                key={mode}
                                                className={cn(
                                                    'block cursor-pointer border px-4 py-3 transition-all',
                                                    selected
                                                        ? 'border-brand bg-brand/10'
                                                        : 'border-white/10 bg-black/30 hover:border-white/25',
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    name="draft_mode"
                                                    value={mode}
                                                    checked={selected}
                                                    onChange={() =>
                                                        form.setData(
                                                            'draft_mode',
                                                            mode,
                                                        )
                                                    }
                                                    className="sr-only"
                                                />
                                                <span className="font-condensed font-semibold tracking-wide uppercase">
                                                    {DraftModeLabels[mode]}
                                                </span>
                                                <p className="mt-1 text-sm text-ink-dim">
                                                    {draftModePitch[mode]}
                                                </p>
                                            </label>
                                        );
                                    },
                                )}
                            </div>
                        </fieldset>

                        <section
                            aria-labelledby="draft-rules"
                            className="border-l-2 border-brand bg-white/[0.03] px-4 py-3"
                            data-brand-tint={form.data.brand}
                        >
                            <h3 id="draft-rules" className="gm-label mb-2">
                                {usesWweRoster
                                    ? 'Roster Rules:'
                                    : 'Draft Rules:'}
                            </h3>
                            <ul className="space-y-1 text-sm text-ink">
                                {draftRules(
                                    starting_budget,
                                    form.data.draft_mode,
                                ).map((rule) => (
                                    <li key={rule} className="flex gap-2">
                                        <span className="text-brand-hot">
                                            ▸
                                        </span>
                                        {rule}
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <div className="grid gap-2">
                            <label htmlFor="save-name" className="gm-label">
                                Save name
                            </label>
                            <input
                                id="save-name"
                                className="gm-input"
                                value={form.data.name}
                                maxLength={60}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name && (
                                <p className="text-sm text-zone-serious">
                                    {form.errors.name}
                                </p>
                            )}
                        </div>

                        <div
                            className="flex items-center justify-between gap-4"
                            data-brand-tint={form.data.brand}
                        >
                            <Link
                                href={gm.docs.url()}
                                className="flex items-center gap-1.5 text-sm text-ink-dim hover:text-ink"
                            >
                                <BookOpen className="size-4" />
                                Documentation
                            </Link>
                            <button
                                type="submit"
                                className="gm-btn"
                                disabled={loading || reel !== null}
                                aria-busy={loading || undefined}
                            >
                                {usesWweRoster
                                    ? 'Play with WWE Roster'
                                    : 'Start the draft'}
                            </button>
                        </div>
                    </form>
                </Panel>

                <Panel title="Saved careers" bodyClassName="p-0">
                    {saves.length === 0 ? (
                        <div className="p-4">
                            <EmptyState>No saves yet.</EmptyState>
                        </div>
                    ) : (
                        <ul className="divide-y divide-white/6">
                            {saves.map((save) => (
                                <li
                                    key={save.id}
                                    data-brand-tint={save.player_brand}
                                    className="flex items-center transition-colors hover:bg-white/[0.03]"
                                >
                                    <Link
                                        href={gm.saves.show.url(save.id)}
                                        className="flex min-w-0 flex-1 items-center gap-4 py-3 pl-4"
                                    >
                                        <BrandBug
                                            brand={save.player_brand}
                                            size="sm"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate font-semibold">
                                                {save.name}
                                            </div>
                                            <div className="font-condensed text-xs tracking-wider text-ink-dim uppercase">
                                                {SaveStageLabels[save.stage]} ·
                                                Season {save.season}, week{' '}
                                                {save.season_week} ·{' '}
                                                {save.month}
                                            </div>
                                        </div>
                                        {save.budget !== null && (
                                            <span
                                                className={cn(
                                                    'gm-num',
                                                    save.budget < 0 &&
                                                        'text-zone-serious',
                                                )}
                                            >
                                                {formatMoney(save.budget)}
                                            </span>
                                        )}
                                    </Link>
                                    <DeleteSaveButton
                                        save={save}
                                        className="mx-1 inline-flex min-h-11 min-w-11 items-center justify-center text-ink-faint transition-colors hover:text-zone-serious focus-visible:text-zone-serious"
                                        label={`Delete ${save.name}`}
                                        title="Delete this career?"
                                        message={`Delete "${save.name}"? This can't be undone.`}
                                        confirmLabel="Delete"
                                    >
                                        <Trash2 className="size-4" />
                                    </DeleteSaveButton>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
