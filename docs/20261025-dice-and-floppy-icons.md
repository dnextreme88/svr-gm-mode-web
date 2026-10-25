# Dice and Floppy Button Icons

Executed **2026-10-25**.

## Why
Randomize had a static die that always showed 5, and only Save match had the floppy. Both icons should be shared by every button of their kind, and they should show that something is happening.

## Dice (`components/gm/dice.tsx`, replaces `dice-icon.tsx`)
- `Dice({ face, rolling })`: the black-and-white die with any face 1–6 (`data-face` for tests).
- While `rolling` it spins (`animate-gm-dice-tumble`) and steps through a fixed face order (no RNG; the arch test bans `Math.random`), then lands on `face`. Reduced motion skips the face cycling; the global reduced-motion rule in `gm.css` stops the spin.
- On **Randomize** (face 5) and **Preset card** (face 6) through `ProposalLauncher`, which rolls while the proposal loads. The `icon` prop is gone.
- On **Re-roll** in `ProposalModal`, landing on `(roll % 6) + 1`, so each re-roll shows a new face.

## Floppy (`components/gm/floppy-icon.tsx`)
- `FloppyIcon({ status })`: `saving` slides the shutter and blinks the label; `saved` pops in a green check (`animate-gm-check-pop`).
- On **Save match**, **Save promo** (new) and **Save career** (replacing the lucide `Save`); the header's "Saved" label shows the floppy with its check (replacing the lucide `Check`).
- Saving a match or promo re-renders the card page and remounts the slot editors, so the status lives in a module store, `lib/save-status.ts`: `saveVisit(key)` gives the Inertia visit callbacks (`saving` on start, `saved` on success for 1,200ms, cleared on error), `useSaveStatus(key)` reads it, and `slotSaveKey(kind, showId, position)` builds the key. `PromoSlots` now takes `showId`.

## CSS
New `@theme` animations in `resources/css/gm.css`: `gm-dice-tumble`, `gm-floppy-write`, `gm-floppy-blink`, `gm-check-pop`.

## Tests
- `dice.test.tsx`: pips per face, tumbling while rolling, landing on the face.
- `floppy-icon.test.tsx`: idle, saving and saved drawings.
- `save-status.test.ts`: saving → saved → cleared, error clears, keys are independent.
- `proposal-launcher.test.tsx`: dice on Randomize (5) and Preset card (6); Re-roll's face changes after a re-roll.
- `promo-slots.test.tsx` / `card-builder.test.tsx`: the floppy is on Save promo and Save match, and follows the save status.
