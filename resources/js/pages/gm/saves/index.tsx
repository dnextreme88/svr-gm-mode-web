import { Head, Link, useForm } from '@inertiajs/react';
import { BrandBug } from '@/components/gm/brand-bug';
import { EmptyState, Panel } from '@/components/gm/panel';
import { formatCompactMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type { Brand, SaveStage } from '@/types/enums';
import { SaveStageLabels } from '@/types/enums';

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

const brandPitch: Record<Brand, { tagline: string; perks: string[] }> = {
    raw: {
        tagline: 'Monday nights. Red ropes. No mercy.',
        perks: [
            "Controls the Women's Championship",
            'Elimination Chamber at New Year’s Revolution',
            'WWE, Intercontinental & World Tag Team titles',
        ],
    },
    smackdown: {
        tagline: 'Friday nights. Blue steel. Fast and loose.',
        perks: [
            'Controls the Cruiserweight Championship',
            'World Heavyweight & United States titles',
            'WWE Tag Team Championship',
        ],
    },
};

export default function Saves({ saves }: { saves: SaveSlot[] }) {
    const form = useForm<{ name: string; brand: Brand }>({
        name: 'My GM Career',
        brand: 'raw',
    });

    return (
        <>
            <Head title="GM Mode" />

            <div className="mb-10 animate-gm-rise text-center">
                <div className="gm-label mb-3 tracking-[0.4em]">
                    SmackDown! vs Raw 2006
                </div>
                <h1 className="gm-heading text-6xl font-bold md:text-8xl">
                    General Manager
                </h1>
                <p className="mt-3 text-ink-dim">
                    Draft a roster, book the shows, protect the gold. One season
                    ends at WrestleMania.
                </p>
            </div>

            <div className="grid gap-8 lg:grid-cols-[1.4fr_1fr]">
                <Panel title="New game">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(gm.saves.store.url());
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
                                                        ? 'border-brand bg-brand/10 shadow-[0_0_40px_-12px_var(--color-brand)]'
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
                                                            key={perk}
                                                            className="flex gap-2"
                                                        >
                                                            <span className="text-brand-hot">
                                                                ▸
                                                            </span>
                                                            {perk}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </label>
                                        );
                                    },
                                )}
                            </div>
                        </fieldset>

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
                            <p className="text-sm text-ink-dim">
                                $15,000,000 budget · 30-wrestler cap · up to 30
                                draft picks
                            </p>
                            <button
                                type="submit"
                                className="gm-btn"
                                disabled={form.processing}
                            >
                                Start the draft
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
                                >
                                    <Link
                                        href={gm.saves.show.url(save.id)}
                                        className="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-white/[0.03]"
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
                                                {formatCompactMoney(
                                                    save.budget,
                                                )}
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
