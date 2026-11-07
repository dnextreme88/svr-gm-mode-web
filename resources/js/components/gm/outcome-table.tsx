import type { MatchOutcome } from '@/types/gm';

/**
 * The match-by-match results table (Reports, a faction's match history):
 * position, match type and title, result, method and the deferred Match
 * Rating and Popularity columns.
 */
export function OutcomeTable({ outcomes }: { outcomes: MatchOutcome[] }) {
    return (
        <div className="gm-scroll overflow-x-auto">
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
                    {outcomes.map((o) => (
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
        </div>
    );
}
