import armageddonLogo from '@/../images/ppvs/armageddon.webp';
import backlashLogo from '@/../images/ppvs/backlash.webp';
import cyberSundayLogo from '@/../images/ppvs/cyber_sunday.webp';
import greatAmericanBashLogo from '@/../images/ppvs/great_american_bash.webp';
import judgmentDayLogo from '@/../images/ppvs/judgment_day.webp';
import newYearsRevolutionLogo from '@/../images/ppvs/new_years_revolution.webp';
import noMercyLogo from '@/../images/ppvs/no_mercy.webp';
import noWayOutLogo from '@/../images/ppvs/no_way_out.webp';
import royalRumbleLogo from '@/../images/ppvs/royal_rumble.webp';
import summerslamLogo from '@/../images/ppvs/summerslam.webp';
import survivorSeriesLogo from '@/../images/ppvs/survivor_series.webp';
import tributeToTheTroopsLogo from '@/../images/ppvs/tribute_to_the_troops.webp';
import unforgivenLogo from '@/../images/ppvs/unforgiven.webp';
import vengeanceLogo from '@/../images/ppvs/vengeance.webp';
import wrestlemaniaLogo from '@/../images/ppvs/wrestlemania.webp';
import rawLogo from '@/../images/brands/raw.webp';
import smackdownLogo from '@/../images/brands/smackdown.webp';
import cruiserweight from '@/../images/titles/cruiserweight.webp';
import intercontinental from '@/../images/titles/intercontinental.webp';
import unitedStates from '@/../images/titles/united_states.webp';
import womens from '@/../images/titles/womens.webp';
import worldHeavyweight from '@/../images/titles/world_heavyweight.webp';
import worldTagTeam from '@/../images/titles/world_tag_team.webp';
import wwe from '@/../images/titles/wwe.webp';
import wweTagTeam from '@/../images/titles/wwe_tag_team.webp';
import { ChampionshipKeyLabels } from '@/types/enums';
import type { Brand, ChampionshipKey, PpvKey } from '@/types/enums';

/** Each brand's 2005 logo. */
export const brandLogo: Record<Brand, string> = {
    raw: rawLogo,
    smackdown: smackdownLogo,
};

/** Each PPV's logo (house rule, 2026-11-11). */
export const ppvLogo: Record<PpvKey, string> = {
    armageddon: armageddonLogo,
    backlash: backlashLogo,
    cyber_sunday: cyberSundayLogo,
    great_american_bash: greatAmericanBashLogo,
    judgment_day: judgmentDayLogo,
    new_years_revolution: newYearsRevolutionLogo,
    no_mercy: noMercyLogo,
    no_way_out: noWayOutLogo,
    royal_rumble: royalRumbleLogo,
    summerslam: summerslamLogo,
    survivor_series: survivorSeriesLogo,
    tribute_to_the_troops: tributeToTheTroopsLogo,
    unforgiven: unforgivenLogo,
    vengeance: vengeanceLogo,
    wrestlemania: wrestlemaniaLogo,
};

/** Each championship's belt. */
export const titleImage: Record<ChampionshipKey, string> = {
    wwe,
    intercontinental,
    world_tag_team: worldTagTeam,
    womens,
    world_heavyweight: worldHeavyweight,
    united_states: unitedStates,
    wwe_tag_team: wweTagTeam,
    cruiserweight,
};

// Championship names are seeded from ChampionshipKey::label(), so a title's
// name is enough to find its belt.
const keyByName = new Map(
    (Object.entries(ChampionshipKeyLabels) as [ChampionshipKey, string][]).map(
        ([key, name]) => [name, key],
    ),
);

/** The belt for a championship by name ("Women's Championship"), or null. */
export function titleImageFor(name: string): string | null {
    const key = keyByName.get(name);

    return key ? titleImage[key] : null;
}
