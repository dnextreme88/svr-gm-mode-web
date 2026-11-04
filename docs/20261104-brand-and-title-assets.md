# Brand logos and title belts in the GM UI

## Context

The UI drew brands as styled text (`BrandBug`: a "RAW" block, a chrome "SMACKDOWN") and titles as gold text chips ("◆ Women's Championship", a gold "Champion" tag on card slots). Real art arrived: the 2005 Raw and Smackdown logos and the 8 title belts. Goal: show the logos in place of the brand text and the belts beside title names. The supplied ratings icon is left out, since Match Rating is still a stub.

## Assets

- `resources/images/brands/{raw,smackdown}.webp`: trimmed of transparent padding, ~385 px wide.
- `resources/images/titles/{ChampionshipKey}.webp`: the 8 belts. The source files share one 800×364 canvas, so each belt keeps that canvas (scaled to 320×146) and the belts stay in proportion to each other. An earlier version trimmed each belt to its own box, which made them different sizes and drew them out of proportion at a shared height.
- Imported through Vite (hashed, bundled) and looked up in `resources/js/lib/assets.ts`: `brandLogo[brand]`, `titleImage[key]` and `titleImageFor(name)`. Championship names come from `ChampionshipKey::label()`, so the name maps back to its key through `ChampionshipKeyLabels`; no backend change.

## Components

- **`BrandBug`** renders the logo image (sizes xs/sm/md/lg = h-4/6/9/14), so every caller switched at once: GM header, card header, rosters, trade, saves, draft, re-draft, Title History.
- **`TitleBeltImage`** (`components/gm/badges.tsx`): the title counterpart, `sm`/`md`/`lg` = h-6/9/14 (the padded canvas needs the extra height), `decorative` when it sits beside the title's name (`alt=""`, `aria-hidden`). Renders nothing for a name with no belt.
- **`TitleMark`**: the belt in a gold title chip, or the old ◆ when there's no image. Used by `TitleBelt` (Rosters, Trade) and the chips on results, promo hype, proposal modal, stipulation title list and title assignment.

## Pages

- **Schedule:** a weekly show is its brand's logo, filling the cell's width (up to h-16), with no red or grey background. A lone weekly show owns its whole day cell: the cell takes its brand-coloured hover, and its button stretches over the cell (`after:absolute after:inset-0`) so a click anywhere opens it. An aired show gets a green ✓ at the right of the date row. PPVs keep their gold bar; this week's glowing border stays. The legend loses the "Your shows" / "Rival shows" swatches.
- **Card slots:** the gold "Champion" tag is replaced by the belt of the title on the line (every contested belt in a Winner Takes All). The title cycler on a match slot shows the plain title name, without a belt or ◆.
- **Results:** the belt sits before the title name on the outcome card and the Book to Win card.
- **News:** the Raw and Smackdown cards show their logo instead of the text ribbon; the WWE ribbon stays.
- **Title History:** belts in Current Champions (stacked above the name, so long names don't break up on phones), the title list and the lineage heading.
- **Rosters and Trade:** the "Diva" tag beside a name is gone (Diva names are already red); the "Legend" tag stays.

## Verification

- Vitest: new `lib/assets.test.ts` and `components/gm/badges.test.tsx`; card builder, schedule, results, promo slot and proposal tests updated. `tsc` and `vp check` clean.
- Checked in the browser: header, card header and slot, schedule (desktop and 375 px), results, news, Title History (desktop and 375 px), rosters.
