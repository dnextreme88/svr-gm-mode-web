/**
 * A match's week as Title History lists it: "S1 W12", with the PPV's name
 * under it when the match was on one. Shared by Title History and the
 * rivalries' match history (house rule, 2026-11-20).
 */
export function WeekCell({
    season,
    week,
    ppv,
}: {
    season: number;
    week: number;
    ppv: string | null;
}) {
    return (
        <>
            <span className="gm-num whitespace-nowrap">
                S{season} W{week}
            </span>
            {ppv && <div className="text-xs text-gold">{ppv}</div>}
        </>
    );
}
