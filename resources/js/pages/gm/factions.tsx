import { Head, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { brandName } from '@/lib/gm';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import type {
    Brand,
    FactionOrigin,
    FactionType,
    RosterType,
    WeightClass,
} from '@/types/enums';
import type { GamePageProps } from '@/types/gm';

type FactionRow = {
    id: number;
    name: string;
    type: FactionType;
    origin: FactionOrigin;
    active: boolean;
    members: Array<{
        id: number;
        name: string;
        roster_type: RosterType;
        weight_class: WeightClass;
        brand: Brand | null;
    }>;
    /** Real-life date for a seeded faction, the in-game show date for a custom one. */
    formed: string | null;
    formed_week: string | null;
    real_disbanded: string | null;
    disbanded: string | null;
    disbanded_week: string | null;
    wins: number;
    losses: number;
    titles: Array<{ id: number; title: string; won: string; current: boolean }>;
};

type Props = GamePageProps & {
    factions: FactionRow[];
    max_members: number;
    formation_wins: number;
};

/** Created: the factions formed in this career, by either brand. */
type OriginFilter = 'created' | 'all';
type StatusFilter = 'all' | 'active' | 'disbanded';

const SECTIONS: Array<{ type: FactionType; title: string }> = [
    { type: 'tag_team', title: 'Tag teams' },
    { type: 'stable', title: 'Stables' },
];

/**
 * Factions (house rule): every tag team and stable in the save, with their
 * record as a team and the titles they won together.
 */
export default function Factions() {
    const { factions, formation_wins, game } = usePage<Props>().props;
    const [origin, setOrigin] = useState<OriginFilter>('created');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [disbanding, setDisbanding] = useState<FactionRow | null>(null);
    const byOrigin = factions.filter(
        (f) => origin === 'all' || f.origin !== 'seeded',
    );
    const shown = byOrigin.filter(
        (f) => status === 'all' || f.active === (status === 'active'),
    );
    const counts: Record<StatusFilter, number> = {
        all: byOrigin.length,
        active: byOrigin.filter((f) => f.active).length,
        disbanded: byOrigin.filter((f) => !f.active).length,
    };

    return (
        <>
            <Head title="Factions" />
            <PageTitle kicker="Tag teams & stables" title="Factions">
                <FilterGroup
                    label="Show"
                    options={[
                        {
                            value: 'created',
                            label: `Created (${factions.filter((f) => f.origin !== 'seeded').length})`,
                        },
                        { value: 'all', label: `All (${factions.length})` },
                    ]}
                    value={origin}
                    onChange={setOrigin}
                />
                <FilterGroup
                    label="Status"
                    options={(['all', 'active', 'disbanded'] as const).map(
                        (v) => ({
                            value: v,
                            label: `${v[0].toUpperCase()}${v.slice(1)} (${counts[v]})`,
                        }),
                    )}
                    value={status}
                    onChange={setStatus}
                />
            </PageTitle>

            <p className="mb-6 max-w-3xl text-sm text-ink-dim">
                When two or more members of a faction team up, they're billed as
                the faction. Two or three male superstars who win{' '}
                {formation_wins} matches together can become a new one.
                Real-life factions show their real dates; they’re all active
                from week 1. New ones show the night they formed.
            </p>

            {shown.length === 0 ? (
                <EmptyState>
                    {byOrigin.length === 0
                        ? `No faction has been created in this career yet. Two or three male superstars who win ${formation_wins} matches together can become one, on either brand.`
                        : status === 'disbanded'
                          ? 'No faction has disbanded in this career.'
                          : 'No active factions.'}
                </EmptyState>
            ) : (
                <div className="space-y-6">
                    {SECTIONS.map(({ type, title }) => {
                        const rows = shown.filter((f) => f.type === type);

                        if (rows.length === 0) {
                            return null;
                        }

                        return (
                            <Panel
                                key={type}
                                title={`${title} (${rows.length})`}
                                bodyClassName="p-0"
                            >
                                <div className="gm-scroll overflow-x-auto">
                                    <table className="gm-table">
                                        <thead>
                                            <tr>
                                                <th>Faction</th>
                                                <th>Members</th>
                                                <th>Formed</th>
                                                <th>Disbanded</th>
                                                <th className="text-right">
                                                    Record
                                                </th>
                                                <th>Titles</th>
                                                {rows.some((f) => f.active) && (
                                                    <th />
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rows.map((f) => (
                                                <FactionTableRow
                                                    key={f.id}
                                                    faction={f}
                                                    actions={rows.some(
                                                        (r) => r.active,
                                                    )}
                                                    onDisband={setDisbanding}
                                                />
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Panel>
                        );
                    })}
                </div>
            )}

            <ConfirmDialog
                open={disbanding !== null}
                danger
                title={`Disband ${disbanding?.name ?? ''}?`}
                message="From this week on they're billed by their own names again. Their record and titles stay on this page. This can't be undone."
                confirmLabel="Disband"
                onCancel={() => setDisbanding(null)}
                onConfirm={() => {
                    if (disbanding) {
                        router.post(
                            gm.factions.disband.url({
                                save: game.id,
                                saveFaction: disbanding.id,
                            }),
                            {},
                            {
                                preserveScroll: true,
                                onFinish: () => setDisbanding(null),
                            },
                        );
                    }
                }}
            />
        </>
    );
}

/** A row of toggle buttons for one filter. */
function FilterGroup<T extends string>({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: Array<{ value: T; label: string }>;
    value: T;
    onChange: (value: T) => void;
}) {
    return (
        <div
            className="flex items-center gap-1"
            role="tablist"
            aria-label={label}
        >
            <span className="gm-label mr-1">{label}</span>
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    role="tab"
                    aria-selected={value === o.value}
                    className={cn(
                        'gm-btn gm-btn-sm whitespace-nowrap',
                        value !== o.value && 'gm-btn-ghost',
                    )}
                    onClick={() => onChange(o.value)}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

function FactionTableRow({
    faction: f,
    actions,
    onDisband,
}: {
    faction: FactionRow;
    /** Whether the table has a Disband column. */
    actions: boolean;
    onDisband: (f: FactionRow) => void;
}) {
    const seeded = f.origin === 'seeded';

    return (
        <tr data-testid={`faction-${f.id}`}>
            <td className="font-semibold whitespace-nowrap">
                {f.name}
                {!seeded && (
                    <span className="gm-label ml-2 text-gold">
                        {f.origin === 'player' ? 'Yours' : 'Rival'}
                    </span>
                )}
            </td>
            <td>
                {f.members.map((m, i) => (
                    <Fragment key={m.id}>
                        {i > 0 && ', '}
                        <span className="whitespace-nowrap">
                            <WrestlerName wrestler={m} />
                            <span className="ml-1 text-xs text-ink-faint">
                                {m.brand ? brandName(m.brand) : 'FA'}
                            </span>
                        </span>
                    </Fragment>
                ))}
            </td>
            <td className="whitespace-nowrap text-ink-dim">
                {f.formed ?? '—'}
                {f.formed_week && (
                    <div className="text-xs text-ink-faint">
                        {f.formed_week}
                    </div>
                )}
            </td>
            <td className="whitespace-nowrap text-ink-dim">
                {f.disbanded ? (
                    <>
                        {f.disbanded}
                        <div className="text-xs text-ink-faint">
                            {f.disbanded_week}
                        </div>
                    </>
                ) : seeded && f.real_disbanded ? (
                    f.real_disbanded
                ) : (
                    '—'
                )}
            </td>
            <td className="gm-num text-right whitespace-nowrap">
                {f.wins}–{f.losses}
            </td>
            <td className="text-sm">
                {f.titles.length === 0 ? (
                    <span className="text-ink-faint">—</span>
                ) : (
                    f.titles.map((t) => (
                        <div
                            key={t.id}
                            className={cn(t.current && 'text-gold-hot')}
                        >
                            {t.title}
                            <span className="ml-1 text-xs text-ink-faint">
                                {t.current ? 'champions' : t.won}
                            </span>
                        </div>
                    ))
                )}
            </td>
            {actions && (
                <td className="text-right">
                    {f.active && (
                        <button
                            type="button"
                            className="gm-btn gm-btn-danger gm-btn-sm"
                            onClick={() => onDisband(f)}
                        >
                            Disband
                        </button>
                    )}
                </td>
            )}
        </tr>
    );
}
