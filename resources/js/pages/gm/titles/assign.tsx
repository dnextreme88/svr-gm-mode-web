import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { TitleMark } from '@/components/gm/badges';
import { PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
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
    'Second tier',
    'Tag titles',
    'Special title',
];

export default function AssignTitles() {
    const { game, championships, initial, current_id } = usePage<Props>().props;
    // Starting the season sets up week 1 on the server, which takes a moment:
    // an overlay covers the page until the schedule opens.
    const [starting, setStarting] = useState(false);
    const startSeason = () =>
        router.post(
            gm.titles.assign.complete.url(game.id),
            {},
            {
                onStart: () => setStarting(true),
                onFinish: () => setStarting(false),
            },
        );

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
            {starting && <StartingSeasonOverlay />}
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
    const change = (c: ChampionshipRow) =>
        router.post(
            gm.titles.assign.undo.url({ save: saveId, championship: c.id }),
            {},
            { preserveScroll: true },
        );
    const changeButton = (c: ChampionshipRow) => (
        <button
            type="button"
            className="gm-btn gm-btn-ghost gm-btn-sm"
            aria-label={`Change ${c.name}`}
            onClick={() => change(c)}
        >
            Change
        </button>
    );

    return (
        <div className="space-y-6">
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
                            <div className="gm-label whitespace-nowrap">
                                {i + 1}. {tierLabels[i] ?? 'Title'}
                            </div>
                            <div className="font-semibold">{c.name}</div>
                            <div
                                className={cn(
                                    'truncate text-sm',
                                    c.holder
                                        ? 'text-gold-hot'
                                        : 'text-ink-faint',
                                )}
                            >
                                {c.holder ??
                                    (c.decided
                                        ? 'Vacant'
                                        : active
                                          ? 'Deciding…'
                                          : 'Up next')}
                            </div>
                            {c.decided && (
                                // Pinned to the bottom, so every tile's
                                // button lines up when names wrap.
                                <div className="mt-auto pt-2">
                                    {changeButton(c)}
                                </div>
                            )}
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
        <button
            type="button"
            className="gm-btn gm-btn-ghost gm-btn-sm"
            onClick={() =>
                router.post(
                    gm.titles.assign.vacant.url(target),
                    {},
                    { preserveScroll: true },
                )
            }
        >
            Leave vacant
        </button>
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
                <span className="font-condensed text-xs tracking-wider text-ink-faint uppercase">
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
                    <div className="gm-scroll grid max-h-72 grid-cols-2 gap-1 overflow-y-auto sm:grid-cols-3">
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
                        <button
                            type="button"
                            className="gm-btn gm-btn-gold gm-btn-sm"
                            disabled={selected.length !== needed}
                            onClick={() =>
                                router.post(
                                    gm.titles.assign.store.url(target),
                                    { save_wrestler_ids: selected },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Award title
                        </button>
                    </div>
                </>
            )}
        </Panel>
    );
}

/**
 * Covers the page while the season starts (house rule, 2026-11-08): a
 * spinner and a line saying so, until the schedule opens.
 */
function StartingSeasonOverlay() {
    return (
        <div
            role="status"
            aria-live="polite"
            data-testid="starting-season"
            className="fixed inset-0 z-50 flex items-center justify-center bg-arena/80 px-4 backdrop-blur-[2px]"
        >
            <div className="gm-panel flex items-center gap-4 border-t-2 border-t-gold px-6 py-5">
                <span
                    aria-hidden
                    className="size-8 shrink-0 rounded-full border-4 border-white/10 border-t-gold motion-safe:animate-spin"
                />
                <span className="font-condensed tracking-wider text-ink uppercase">
                    Starting the season. Please wait...
                </span>
            </div>
        </div>
    );
}
