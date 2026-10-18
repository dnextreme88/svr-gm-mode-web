import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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

function confirmDelete(save: SaveSlot, message: string) {
    if (confirm(message)) {
        router.delete(gm.saves.destroy.url(save.id), { preserveScroll: true });
    }
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
            <button
                type="button"
                className="gm-btn gm-btn-sm gm-btn-ghost"
                onClick={() =>
                    confirmDelete(
                        save,
                        `Discard "${save.name}"? It was never saved.`,
                    )
                }
            >
                Discard
            </button>
        </div>
    );
}

export default function Saves({
    saves,
    unsaved,
}: {
    saves: SaveSlot[];
    unsaved: SaveSlot | null;
}) {
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

            {unsaved && <UnsavedCareer save={unsaved} />}

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
                                                {formatCompactMoney(
                                                    save.budget,
                                                )}
                                            </span>
                                        )}
                                    </Link>
                                    <button
                                        type="button"
                                        className="mx-3 p-2 text-ink-faint transition-colors hover:text-zone-serious focus-visible:text-zone-serious"
                                        aria-label={`Delete ${save.name}`}
                                        title="Delete this career"
                                        onClick={() =>
                                            confirmDelete(
                                                save,
                                                `Delete "${save.name}"? This can't be undone.`,
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
