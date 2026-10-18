import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageTitle, Panel } from '@/components/gm/panel';
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
    eligible: WrestlerRow[];
};

type Props = GamePageProps & {
    championships: ChampionshipRow[];
    initial: boolean;
};

export default function AssignTitles() {
    const { game, championships, initial } = usePage<Props>().props;

    return (
        <>
            <Head title="Assign Championships" />
            <PageTitle
                kicker={initial ? 'After the draft' : 'Vacant titles'}
                title="Crown your champions"
            >
                {initial && (
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
                Each assignment opens reign #1 of that title’s history. Titles
                you leave unassigned start the season vacant.
            </p>

            <div className="grid gap-6 lg:grid-cols-2">
                {championships.map((c) => (
                    <TitleCard key={c.id} saveId={game.id} championship={c} />
                ))}
            </div>
        </>
    );
}

function TitleCard({
    saveId,
    championship,
}: {
    saveId: number;
    championship: ChampionshipRow;
}) {
    const needed = championship.is_tag ? 2 : 1;
    const [selected, setSelected] = useState<number[]>([]);

    const toggle = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((x) => x !== id)
                : [...current, id].slice(-needed),
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
                <p className="text-sm text-ink-dim">
                    No eligible wrestler on your roster. This title starts
                    vacant.
                </p>
            ) : (
                <>
                    <div className="gm-scroll grid max-h-56 grid-cols-2 gap-1 overflow-y-auto">
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
                                {w.name}
                            </button>
                        ))}
                    </div>
                    <div className="mt-3 flex justify-end">
                        <button
                            type="button"
                            className="gm-btn gm-btn-gold gm-btn-sm"
                            disabled={selected.length !== needed}
                            onClick={() =>
                                router.post(
                                    gm.titles.assign.store.url({
                                        save: saveId,
                                        championship: championship.id,
                                    }),
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
