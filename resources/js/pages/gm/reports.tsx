import { Head, usePage } from '@inertiajs/react';
import { AttendanceChart } from '@/components/gm/attendance-chart';
import type { AttendancePoint } from '@/components/gm/attendance-chart';
import { OutcomeTable } from '@/components/gm/outcome-table';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import type { CardShow, GamePageProps, MatchOutcome } from '@/types/gm';

type Props = GamePageProps & {
    shows: Array<CardShow & { outcomes: MatchOutcome[] }>;
    /** Every aired night's crowd, both brands (house rule, 2026-11-01). */
    attendance: AttendancePoint[];
};

export default function Reports() {
    const { shows, attendance } = usePage<Props>().props;

    return (
        <>
            <Head title="Reports" />
            <PageTitle kicker="Match by match" title="Reports" />
            <p className="-mt-3 mb-6 max-w-2xl text-sm text-ink-dim">
                Star ratings and fan support come from the Match Rating and
                Popularity systems, which haven’t been designed yet.
            </p>

            <Panel title="Fan attendance" className="mb-6">
                <AttendanceChart points={attendance} />
            </Panel>

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
                                <OutcomeTable outcomes={show.outcomes} />
                            )}
                        </Panel>
                    ))}
                </div>
            )}
        </>
    );
}
