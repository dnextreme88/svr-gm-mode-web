import { Head, usePage } from '@inertiajs/react';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import type { CardShow, GamePageProps, MatchOutcome } from '@/types/gm';

type Props = GamePageProps & {
    shows: Array<CardShow & { outcomes: MatchOutcome[] }>;
};

export default function Reports() {
    const { shows } = usePage<Props>().props;

    return (
        <>
            <Head title="Reports" />
            <PageTitle kicker="Match by match" title="Reports" />
            <p className="-mt-3 mb-6 max-w-2xl text-sm text-ink-dim">
                Star ratings and fan support come from the Match Rating and
                Popularity systems, which haven’t been designed yet.
            </p>

            {shows.length === 0 ? (
                <EmptyState>No shows have aired yet.</EmptyState>
            ) : (
                <div className="space-y-6">
                    {shows.map((show) => (
                        <Panel
                            key={show.id}
                            title={`Week ${show.week_number} · ${show.name}`}
                            bodyClassName="p-0"
                        >
                            {show.outcomes.length === 0 ? (
                                <p className="px-4 py-3 text-sm text-ink-faint">
                                    No matches.
                                </p>
                            ) : (
                                <table className="gm-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Match</th>
                                            <th>Result</th>
                                            <th>Method</th>
                                            <th>Stars</th>
                                            <th>Fan support</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {show.outcomes.map((o) => (
                                            <tr key={o.position}>
                                                <td className="gm-num text-ink-faint">
                                                    {o.position}
                                                </td>
                                                <td className="text-ink-dim">
                                                    {o.match_type}
                                                    {o.title && (
                                                        <div className="text-xs text-gold-hot">
                                                            {o.title}
                                                        </div>
                                                    )}
                                                </td>
                                                <td>
                                                    <span className="font-semibold">
                                                        {o.winners_label}
                                                    </span>
                                                    <span className="mx-1.5 text-ink-faint">
                                                        def.
                                                    </span>
                                                    {o.losers.join(', ')}
                                                </td>
                                                <td className="text-ink-dim">
                                                    {o.method_label}
                                                    {o.finish_label && (
                                                        <div className="text-xs">
                                                            {o.finish_label}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="text-ink-faint">
                                                    {o.rating ?? '—'}
                                                </td>
                                                <td className="text-ink-faint">
                                                    {o.fan_delta ?? '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </Panel>
                    ))}
                </div>
            )}
        </>
    );
}
