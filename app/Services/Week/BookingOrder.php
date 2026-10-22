<?php

namespace App\Services\Week;

use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Models\Show;
use Illuminate\Support\Collection;

/**
 * The player books a week's cards in date order. Weeks start on Sunday, so a
 * PPV week runs: the brand's PPV card, then the interbrand card (Royal
 * Rumble / Survivor Series entrants, or WrestleMania), then the weekly show.
 * Each card is confirmed before the next one opens; the weekly show is last
 * and its Air button airs the whole week. A confirmed card is read-only for
 * the player until they unlock it.
 */
class BookingOrder
{
    /**
     * The player's cards for a week, in the order they're booked.
     *
     * @return Collection<int, Show>
     */
    public function steps(Save $save, int $week): Collection
    {
        return Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('week_number', $week)
            ->where(fn ($q) => $q->where('brand', $save->player_brand)->orWhereNull('brand'))
            ->get()
            ->sortBy(fn (Show $show): int => match (true) {
                $show->isPpv() && ! $show->isInterbrand() => 0,
                $show->isInterbrand() => 1,
                default => 2,
            })
            ->values();
    }

    /**
     * The earlier card that still needs confirming before this one opens.
     */
    public function blocker(Save $save, Show $show): ?Show
    {
        foreach ($this->steps($save, $show->week_number) as $step) {
            if ($step->is($show)) {
                return null;
            }

            if ($step->confirmed_at === null) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The card after this one, or null when this is the week's last card.
     */
    public function next(Save $save, Show $show): ?Show
    {
        $steps = $this->steps($save, $show->week_number);
        $index = $steps->search(fn (Show $s): bool => $s->is($show));

        return $index === false ? null : $steps->get($index + 1);
    }

    public function isLast(Save $save, Show $show): bool
    {
        return $this->next($save, $show) === null;
    }

    /**
     * Throws unless the player may change this card right now.
     */
    public function assertEditable(Save $save, Show $show): void
    {
        if ($show->confirmed_at !== null) {
            throw new GameRuleException("{$show->name} is confirmed. Unlock it to make changes.");
        }

        $blocker = $this->blocker($save, $show);

        if ($blocker !== null) {
            throw new GameRuleException("Book {$blocker->name} first. This week's cards are booked in date order.");
        }
    }

    /**
     * Finish a card and open the next one. The week's last card isn't
     * confirmed: its Air button airs the week instead.
     */
    public function confirm(Save $save, Show $show): Show
    {
        $this->assertEditable($save, $show);
        $next = $this->next($save, $show);

        if ($next === null) {
            throw new GameRuleException("{$show->name} is the last card this week. Air the week instead.");
        }

        $show->update(['confirmed_at' => now()]);

        return $next;
    }

    /**
     * Reopen a confirmed card, along with every card after it.
     */
    public function unlock(Save $save, Show $show): void
    {
        if ($show->week_number !== $save->current_week) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        $steps = $this->steps($save, $show->week_number);
        $from = $steps->search(fn (Show $s): bool => $s->is($show));

        foreach ($steps->slice((int) $from) as $step) {
            $step->update(['confirmed_at' => null]);
        }
    }

    /**
     * Throws unless every card before the week's last one is confirmed.
     */
    public function assertReadyToAir(Save $save, int $week): void
    {
        $steps = $this->steps($save, $week);

        foreach ($steps->slice(0, max(0, $steps->count() - 1)) as $step) {
            if ($step->confirmed_at === null) {
                throw new GameRuleException("Book and confirm {$step->name} before the week can air.");
            }
        }
    }

    /**
     * Confirm every card of the week (used when the computer books them).
     */
    public function confirmAll(Save $save, int $week): void
    {
        foreach ($this->steps($save, $week) as $step) {
            $step->update(['confirmed_at' => $step->confirmed_at ?? now()]);
        }
    }
}
