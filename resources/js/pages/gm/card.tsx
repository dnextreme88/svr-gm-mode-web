import { Head, Link, router, usePage } from '@inertiajs/react';
import { BrandBug } from '@/components/gm/brand-bug';
import { CardBuilder } from '@/components/gm/card-builder';
import { PageTitle } from '@/components/gm/panel';
import gm from '@/routes/gm';
import type {
    CardShow,
    CardSlot,
    GamePageProps,
    MatchTypeOption,
    RosterEntry,
} from '@/types/gm';

type Props = GamePageProps & {
    show: CardShow;
    slots: CardSlot[];
    match_types: MatchTypeOption[];
    roster: RosterEntry[];
};

export default function CardPage() {
    const { game, show, slots, match_types, roster } = usePage<Props>().props;

    return (
        <>
            <Head title={`${show.name} card`} />
            <PageTitle
                kicker={`Week ${game.season_week} · ${show.show_type === 'ppv' ? 'PPV card' : 'Weekly show'}`}
                title={
                    <span className="flex flex-wrap items-center gap-4">
                        {show.brand && (
                            <BrandBug brand={show.brand} size="lg" />
                        )}
                        {show.name}
                    </span>
                }
            >
                <Link
                    href={gm.schedule.url(game.id)}
                    className="gm-btn gm-btn-ghost gm-btn-sm"
                >
                    Schedule
                </Link>
            </PageTitle>

            <CardBuilder
                key={`${show.id}-${slots.map((s) => `${s.position}:${s.match_type_id}`).join(',')}`}
                show={show}
                slots={slots}
                matchTypes={match_types}
                roster={roster}
                fatigueConfig={game.fatigue_config}
                onSave={(position, payload) =>
                    router.put(
                        gm.card.book.url({
                            save: game.id,
                            show: show.id,
                            position,
                        }),
                        payload,
                        { preserveScroll: true },
                    )
                }
                onClear={(position) =>
                    router.delete(
                        gm.card.clear.url({
                            save: game.id,
                            show: show.id,
                            position,
                        }),
                        { preserveScroll: true },
                    )
                }
            />
        </>
    );
}
