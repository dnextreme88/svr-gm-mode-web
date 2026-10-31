import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
                        onClick={() =>
                            router.post(gm.titles.assign.complete.url(game.id))
                        }
                    >
                        Start the season
                    </button>
                )}
            </PageTitle>
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

    return (
        <div className="space-y-6">
            <ol className="grid gap-2 sm:grid-cols-4" aria-label="Title steps">
                {championships.map((c, i) => {
                    const active = c.id === currentId;

                    return (
                        <li
                            key={c.id}
                            aria-current={active ? 'step' : undefined}
                            data-testid={`step-${c.key}`}
                            className={cn(
                                'border px-3 py-2',
                                active
                                    ? 'border-gold/60 bg-gold/10'
                                    : 'border-white/8 bg-black/20',
                            )}
                        >
                            <div className="gm-label">
                                {i + 1}. {tierLabels[i] ?? 'Title'}
                            </div>
                            <div className="truncate font-semibold">
                                {c.name}
                            </div>
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
                <Panel>
                    <p className="text-sm text-ink-dim">
                        Every title is decided. Start the season when you’re
                        ready.
                    </p>
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
                    <span className="text-gold">◆</span>
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
