import type { MatchOutcome } from '@/types/gm';

/**
 * The match-by-match results (Reports, a faction's match history): position,
 * match type and title, result and method. A table from `md` up; on a phone
 * each match is a two-line row, so nothing is squeezed or cut off (UI audit,
 * 2026-11-12). Star ratings and fan support join once the Match Rating and
 * Popularity systems exist.
 */
export function OutcomeTable({ outcomes }: { outcomes: MatchOutcome[] }) {
    return (
        <>
            <ol className="divide-y divide-white/5 md:hidden">
                {outcomes.map((o) => (
                    <li
                        key={o.position}
                        className="grid grid-cols-[1.5rem_1fr] gap-x-2 px-4 py-3 text-sm"
                        data-testid="outcome-row"
                    >
                        <span className="gm-num text-ink-faint">
                            {o.position}
                        </span>
                        <div className="min-w-0">
                            <div>
                                <span className="font-semibold">
                                    {o.winners_label}
                                </span>
                                <span className="mx-1.5 text-ink-faint">
                                    def.
                                </span>
                                {o.losers.join(', ')}
                            </div>
                            <div className="mt-0.5 text-xs text-ink-dim">
                                {o.match_type}
                                {o.title && (
                                    <span className="text-gold-hot">
                                        {' '}
                                        · {o.title}
                                    </span>
                                )}
                                {' · '}
                                {o.method_label}
                                {o.finish_label && ` (${o.finish_label})`}
                            </div>
                        </div>
                    </li>
                ))}
            </ol>
            <div className="gm-scroll hidden overflow-x-auto md:block">
                <table className="gm-table">
                    <thead>
                        <tr>
                            <th className="text-right">#</th>
                            <th>Match</th>
                            <th>Result</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        {outcomes.map((o) => (
                            <tr key={o.position}>
                                <td className="gm-num text-right text-ink-faint">
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
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}
