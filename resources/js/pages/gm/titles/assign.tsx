import { Head, router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/gm/action-button';
import { useState } from 'react';
import { TitleMark } from '@/components/gm/badges';
import { CardProgress } from '@/components/gm/card-builder';
import {
    loadingOverlayDelay,
    LoadingOverlay,
} from '@/components/gm/loading-overlay';
import { PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { pluralize } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import { ChampionshipEligibilityLabels } from '@/types/enums';
import type { ChampionshipEligibility } from '@/types/enums';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type ChampionshipRow = {
    id: number;
    key: string;
    name: string;
    is_tag: boolean;
    eligibility: ChampionshipEligibility;
    holder: string | null;
    decided: boolean;
    eligible: WrestlerRow[];
};

type Props = GamePageProps & {
    championships: ChampionshipRow[];
    initial: boolean;
    current_id: number | null;
};

const tierLabels = [
    'World title',
    'Secondary title',
    'Tag titles',
    'Special title',
];

export default function AssignTitles() {
    const { game, championships, initial, current_id } = usePage<Props>().props;
    // Starting the season sets up week 1 on the server, which takes a moment:
    // an overlay covers the page until the schedule opens.
    const [starting, setStarting] = useState(false);
    const startSeason = () => {
        // The overlay shows at once and stays at least this long before the
        // season starts, so it doesn't just flash.
        setStarting(true);
        window.setTimeout(
            () =>
                router.post(
                    gm.titles.assign.complete.url(game.id),
                    {},
                    { onFinish: () => setStarting(false) },
                ),
            loadingOverlayDelay('season-start'),
        );
    };

    return (
        <>
            <Head title="Assign Championships" />
            <PageTitle
                kicker={initial ? 'After the draft' : 'Vacant titles'}
                title="Crown your champions"
            >
                {initial && current_id === null && (
                    <button
                        type="button"
                        className="gm-btn gm-btn-gold"
                        disabled={starting}
                        onClick={startSeason}
                    >
                        Start the season
                    </button>
                )}
            </PageTitle>
            {starting && (
                <LoadingOverlay
                    testId="starting-season"
                    text="Starting the season. Please wait..."
                />
            )}
            <p className="-mt-3 mb-6 max-w-2xl text-sm text-ink-dim">
                {initial
                    ? 'Decide each title in turn: crown a champion or leave it vacant. Each assignment opens reign #1. A superstar can only hold one title.'
                    : 'Award a vacant title. A superstar can only be awarded a title if they don’t already hold one.'}
            </p>

            {initial ? (
                <CrownSteps
                    saveId={game.id}
                    championships={championships}
                    currentId={current_id}
                />
            ) : (
                <div className="grid gap-6 lg:grid-cols-2">
                    {championships.map((c) => (
                        <TitleCard
                            key={c.id}
                            saveId={game.id}
                            championship={c}
                        />
                    ))}
                </div>
            )}
        </>
    );
}

/**
 * After the draft, titles are decided one at a time: world, second tier,
 * tag, then the brand's special title.
 */
function CrownSteps({
    saveId,
    championships,
    currentId,
}: {
    saveId: number;
    championships: ChampionshipRow[];
    currentId: number | null;
}) {
    const current = championships.find((c) => c.id === currentId) ?? null;
    // House rule (2026-11-07): any decided title can be taken back and
    // decided again until the season starts.
    const changeButton = (c: ChampionshipRow) => (
        <ActionButton
            className="gm-btn gm-btn-ghost gm-btn-sm"
            label={`Change ${c.name}`}
            busyText="Reopening…"
            onAction={(track) =>
                router.post(
                    gm.titles.assign.undo.url({
                        save: saveId,
                        championship: c.id,
                    }),
                    {},
                    { preserveScroll: true, ...track },
                )
            }
        >
            Change
        </ActionButton>
    );

    const progress = championsProgress(championships);

    return (
        <div className="space-y-6">
            <div data-testid="champions-progress" className="flex">
                <CardProgress
                    booked={progress.assigned}
                    total={progress.total}
                    label={`${progress.assigned}/${pluralize(progress.total, 'champion')} assigned`}
                    ariaLabel="Champions assigned"
                />
            </div>
            {/* 2 × 2 on sm and md, four in a row from lg, so names don't cut off. */}
            <ol
                className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
                aria-label="Title steps"
            >
                {championships.map((c, i) => {
                    const active = c.id === currentId;

                    return (
                        <li
                            key={c.id}
                            aria-current={active ? 'step' : undefined}
                            data-testid={`step-${c.key}`}
                            className={cn(
                                'flex flex-col border px-3 py-2',
                                active
                                    ? 'border-gold/60 bg-gold/10'
                                    : 'border-white/8 bg-black/20',
                            )}
                        >
                            <div className="gm-label text-xl whitespace-nowrap">
                                {i + 1}. {tierLabels[i] ?? 'Title'}
                            </div>
                            <div className="text-lg font-semibold">
                                {c.name}
                            </div>
                            <div
                                className={cn(
                                    'truncate text-sm',
                                    c.holder
                                        ? 'text-gold-hot'
                                        : 'text-ink-faint',
                                    // Only the title being decided pulses.
                                    active &&
                                        !c.decided &&
                                        'motion-safe:animate-gm-pulse',
                                )}
                            >
                                {c.holder ??
                                    (c.decided
                                        ? 'Vacant'
                                        : active
                                          ? 'Deciding…'
                                          : 'Up next')}
                            </div>
                        </li>
                    );
                })}
            </ol>

            {current ? (
                <TitleCard
                    key={current.id}
                    saveId={saveId}
                    championship={current}
                    allowVacant
                />
            ) : (
                <Panel title="Review your champions">
                    <p className="mb-3 text-sm text-ink-dim">
                        Every title is decided. Change any of them, or start the
                        season when you’re ready.
                    </p>
                    <ul
                        className="divide-y divide-white/5"
                        aria-label="Your champions"
                    >
                        {championships.map((c) => (
                            <li
                                key={c.id}
                                data-testid={`review-${c.key}`}
                                // Fixed columns, so every holder (or Vacant)
                                // starts at the same point. On phones the
                                // holder sits under the title instead.
                                className="grid grid-cols-[3rem_minmax(0,1fr)_auto] items-center gap-x-3 py-2 sm:grid-cols-[3rem_16rem_minmax(0,1fr)_auto]"
                            >
                                <span className="row-span-2 flex justify-center text-gold sm:row-span-1">
                                    <TitleMark name={c.name} />
                                </span>
                                <span className="font-semibold">{c.name}</span>
                                <span
                                    data-testid={`review-holder-${c.key}`}
                                    className={cn(
                                        'col-start-2 text-sm sm:col-start-3 sm:row-start-1',
                                        c.holder
                                            ? 'text-gold-hot'
                                            : 'text-ink-faint',
                                    )}
                                >
                                    {c.holder ?? 'Vacant'}
                                </span>
                                <span className="col-start-3 row-span-2 row-start-1 sm:col-start-4 sm:row-span-1">
                                    {changeButton(c)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </div>
    );
}

/**
 * Champions crowned so far, out of every champion still needed: one per
 * title, two for the tag titles (so 0/5 to start). A vacant title
 * stays in the total, unfilled.
 */
export function championsProgress(championships: ChampionshipRow[]): {
    assigned: number;
    total: number;
} {
    const needed = (c: ChampionshipRow) => (c.is_tag ? 2 : 1);

    return championships.reduce(
        (sum, c) => ({
            assigned: sum.assigned + (c.holder ? needed(c) : 0),
            total: sum.total + needed(c),
        }),
        { assigned: 0, total: 0 },
    );
}

function TitleCard({
    saveId,
    championship,
    allowVacant = false,
}: {
    saveId: number;
    championship: ChampionshipRow;
    allowVacant?: boolean;
}) {
    const needed = championship.is_tag ? 2 : 1;
    const [selected, setSelected] = useState<number[]>([]);

    const toggle = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((x) => x !== id)
                : [...current, id].slice(-needed),
        );

    const target = { save: saveId, championship: championship.id };

    const vacantButton = allowVacant && (
        <ActionButton
            className="gm-btn gm-btn-ghost gm-btn-sm"
            busyText="Saving…"
            onAction={(track) =>
                router.post(
                    gm.titles.assign.vacant.url(target),
                    {},
                    { preserveScroll: true, ...track },
                )
            }
        >
            Leave vacant
        </ActionButton>
    );

    return (
        <Panel
            title={
                <span className="flex items-center gap-2">
                    <span className="text-gold">
                        <TitleMark name={championship.name} />
                    </span>
                    {championship.name}
                </span>
            }
            actions={
                <span className="font-condensed text-sm tracking-wider text-ink-faint uppercase">
                    {ChampionshipEligibilityLabels[championship.eligibility]}
                    {championship.is_tag && ' · pick 2'}
                </span>
            }
        >
            {championship.holder ? (
                <div className="flex items-center justify-between">
                    <span className="gm-heading text-2xl text-gold-hot">
                        {championship.holder}
                    </span>
                    <span className="gm-label">Reign #1</span>
                </div>
            ) : championship.eligible.length < needed ? (
                <div className="flex items-center justify-between gap-4">
                    <p className="text-sm text-ink-dim">
                        No eligible superstar left on your roster. This title
                        starts vacant.
                    </p>
                    {vacantButton}
                </div>
            ) : (
                <>
                    <div className="gm-scroll grid max-h-72 grid-cols-1 gap-1 overflow-y-auto md:grid-cols-2 lg:max-h-none lg:grid-cols-3 lg:overflow-visible">
                        {championship.eligible.map((w) => (
                            <button
                                key={w.id}
                                type="button"
                                aria-pressed={selected.includes(w.id)}
                                onClick={() => toggle(w.id)}
                                className={cn(
                                    'px-2 py-1.5 text-left text-sm transition-colors',
                                    selected.includes(w.id)
                                        ? 'bg-gold/20 text-gold-hot ring-1 ring-gold/50'
                                        : 'bg-white/[0.03] hover:bg-white/[0.07]',
                                )}
                            >
                                <WrestlerName wrestler={w} />
                            </button>
                        ))}
                    </div>
                    <div className="mt-3 flex justify-end gap-2">
                        {vacantButton}
                        <ActionButton
                            className="gm-btn gm-btn-gold gm-btn-sm"
                            disabled={selected.length !== needed}
                            busyText="Awarding…"
                            onAction={(track) =>
                                router.post(
                                    gm.titles.assign.store.url(target),
                                    { save_wrestler_ids: selected },
                                    { preserveScroll: true, ...track },
                                )
                            }
                        >
                            Award title
                        </ActionButton>
                    </div>
                </>
            )}
        </Panel>
    );
}
