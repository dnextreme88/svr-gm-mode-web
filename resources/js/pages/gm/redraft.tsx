import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { BrandBug } from '@/components/gm/brand-bug';
import { PageTitle, Panel, Stat } from '@/components/gm/panel';
import { brandName } from '@/lib/gm';
import gm from '@/routes/gm';
import type { Brand } from '@/types/enums';
import type { GamePageProps, WrestlerRow } from '@/types/gm';

type Props = GamePageProps & {
    in_progress: boolean;
    series: {
        raw_wins: number;
        smackdown_wins: number;
        winner_brand: Brand | null;
        exemption_wrestler_id: number | null;
        handpick_wrestler_id: number | null;
    } | null;
    you_won: boolean;
    own_eligible: WrestlerRow[];
    rival_eligible: WrestlerRow[];
    max_picks: number;
    picks: Array<{
        pick_number: number;
        brand: Brand;
        name: string | null;
        pick_type: 'manual' | 'random';
        skipped: boolean;
    }>;
};

/**
 * The post-WrestleMania re-draft (§13, §23).
 */
export default function Redraft() {
    const {
        game,
        in_progress,
        series,
        you_won,
        own_eligible,
        rival_eligible,
        max_picks,
        picks,
    } = usePage<Props>().props;
    const [exemption, setExemption] = useState<number | ''>(
        series?.exemption_wrestler_id ?? '',
    );
    const [handpick, setHandpick] = useState<number | ''>(
        series?.handpick_wrestler_id ?? '',
    );

    return (
        <>
            <Head title="WrestleMania Re-Draft" />
            <PageTitle kicker="A new year begins" title="The Re-Draft">
                {!in_progress && (
                    <Link href={gm.schedule.url(game.id)} className="gm-btn">
                        On to the new season
                    </Link>
                )}
            </PageTitle>

            {series && (
                <div className="mb-6 grid gap-4 md:grid-cols-3">
                    <Panel>
                        <Stat label="Raw series wins" value={series.raw_wins} />
                    </Panel>
                    <Panel>
                        <Stat
                            label="Smackdown series wins"
                            value={series.smackdown_wins}
                        />
                    </Panel>
                    <Panel>
                        <div className="gm-label">Brand battle winner</div>
                        <div className="mt-2">
                            {series.winner_brand ? (
                                <BrandBug brand={series.winner_brand} />
                            ) : (
                                'No winner'
                            )}
                        </div>
                    </Panel>
                </div>
            )}

            {in_progress && (
                <Panel title="Before the picks" className="mb-6">
                    <p className="mb-4 text-sm text-ink-dim">
                        Each brand gets up to {max_picks} random picks from the
                        rival’s non-champions, in a random order that then
                        alternates. Drafted wrestlers keep their contracts and
                        morale. A brand at the roster cap skips its remaining
                        picks.
                        {series?.winner_brand &&
                            ` ${brandName(series.winner_brand)} won the brand battle: one protected wrestler, and one hand-pick in place of a random pick.`}
                    </p>

                    {you_won && (
                        <div className="mb-4 grid gap-4 md:grid-cols-2">
                            <label className="grid gap-1 text-sm">
                                <span className="gm-label">
                                    Exemption: protect one of yours
                                </span>
                                <select
                                    className="gm-input"
                                    value={exemption}
                                    onChange={(e) =>
                                        setExemption(
                                            e.target.value
                                                ? Number(e.target.value)
                                                : '',
                                        )
                                    }
                                >
                                    <option value="">None</option>
                                    {own_eligible.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                <span className="gm-label">
                                    Hand-pick from {brandName(game.rival_brand)}
                                </span>
                                <select
                                    className="gm-input"
                                    value={handpick}
                                    onChange={(e) =>
                                        setHandpick(
                                            e.target.value
                                                ? Number(e.target.value)
                                                : '',
                                        )
                                    }
                                >
                                    <option value="">None (random)</option>
                                    {rival_eligible.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <div className="md:col-span-2">
                                <button
                                    type="button"
                                    className="gm-btn gm-btn-ghost gm-btn-sm"
                                    onClick={() =>
                                        router.post(
                                            gm.redraft.tags.url(game.id),
                                            {
                                                exemption_wrestler_id:
                                                    exemption || null,
                                                handpick_wrestler_id:
                                                    handpick || null,
                                            },
                                        )
                                    }
                                >
                                    Save tags
                                </button>
                            </div>
                        </div>
                    )}

                    <button
                        type="button"
                        className="gm-btn gm-btn-gold"
                        onClick={() => router.post(gm.redraft.run.url(game.id))}
                    >
                        Run the re-draft
                    </button>
                </Panel>
            )}

            {picks.length > 0 && (
                <Panel title="Picks" bodyClassName="p-0">
                    <ol className="divide-y divide-white/5 text-sm">
                        {picks.map((p) => (
                            <li
                                key={p.pick_number}
                                data-brand-tint={p.brand}
                                className="flex items-center gap-3 px-4 py-2"
                            >
                                <span className="gm-num w-8 text-ink-faint">
                                    #{p.pick_number}
                                </span>
                                <BrandBug brand={p.brand} size="sm" />
                                <span className="flex-1 font-medium">
                                    {p.skipped ? (
                                        <span className="text-ink-faint">
                                            Skipped
                                        </span>
                                    ) : (
                                        p.name
                                    )}
                                </span>
                                {p.pick_type === 'manual' && (
                                    <span className="gm-label text-gold-hot">
                                        Hand-pick
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                </Panel>
            )}
        </>
    );
}
