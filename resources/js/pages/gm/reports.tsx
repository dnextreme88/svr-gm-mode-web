import { Head, usePage } from '@inertiajs/react';
import { AttendanceChart } from '@/components/gm/attendance-chart';
import type {
    AttendancePoint,
    SharedPpvMark,
} from '@/components/gm/attendance-chart';
import { OutcomeTable } from '@/components/gm/outcome-table';
import { EmptyState, PageTitle, Panel } from '@/components/gm/panel';
import type { CardShow, GamePageProps, MatchOutcome } from '@/types/gm';

type Props = GamePageProps & {
    shows: Array<CardShow & { outcomes: MatchOutcome[] }>;
    /** Every aired night's crowd, both brands (house rule, 2026-11-01). */
    attendance: AttendancePoint[];
    /** The shared PPVs that mark the chart's week axis (house rule, 2026-11-11). */
    shared_ppvs?: SharedPpvMark[];
};

export default function Reports() {
    const { shows, attendance, shared_ppvs = [] } = usePage<Props>().props;

    return (
        <>
            <Head title="Reports" />
            <PageTitle kicker="Match by match" title="Reports" />

            <Panel title="Fan attendance" className="mb-6">
                <AttendanceChart points={attendance} sharedPpvs={shared_ppvs} />
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
