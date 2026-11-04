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
import type { Brand, ChampionshipKey } from '@/types/enums';

/** Each brand's 2005 logo. */
export const brandLogo: Record<Brand, string> = {
    raw: rawLogo,
    smackdown: smackdownLogo,
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
