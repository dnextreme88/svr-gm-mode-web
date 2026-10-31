<?php

namespace App\Services\Week;

use App\Enums\ShowStatus;
use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Models\Show;
use Illuminate\Support\Collection;

/**
 * The player books a week's cards in date order. Weeks start on Sunday, so a
 * PPV week runs: the brand's PPV card, then the interbrand card (Royal
 * Rumble / Survivor Series entrants, SummerSlam or WrestleMania), then the
 * weekly show. Each card is confirmed before the next one opens. House rule
 * (2026-11-01): the PPV night and the weekly show are booked and aired
 * apart. The PPV night's last card airs it; the weekly show opens once the
 * PPV has aired, and its Air button airs the rest of the week. A confirmed
 * card is read-only for the player until they unlock it.
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
     * Which night a card is on: the PPV night or the weekly show.
     */
    public static function night(Show $show): string
    {
        return $show->isPpv() ? 'ppv' : 'weekly';
    }

    /**
     * The earlier card that must come first: one of the same night still to
     * confirm, or a PPV card that hasn't aired yet (see `waitsForAiring`).
     */
    public function blocker(Save $save, Show $show): ?Show
    {
        foreach ($this->steps($save, $show->week_number) as $step) {
            if ($step->is($show)) {
                return null;
            }

            if (self::night($step) === self::night($show) ? $step->confirmed_at === null : $step->status !== ShowStatus::Aired) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Whether this card waits for an earlier night to air, not to be confirmed.
     */
    public function waitsForAiring(Save $save, Show $show): bool
    {
        $blocker = $this->blocker($save, $show);

        return $blocker !== null && self::night($blocker) !== self::night($show);
    }

    /**
     * The next card of the same night, or null when this card airs it.
     */
    public function next(Save $save, Show $show): ?Show
    {
        $steps = $this->steps($save, $show->week_number);
        $index = $steps->search(fn (Show $s): bool => $s->is($show));
        $next = $index === false ? null : $steps->get($index + 1);

        return $next !== null && self::night($next) === self::night($show) ? $next : null;
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
        if ($show->status !== ShowStatus::Booking) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        if ($show->confirmed_at !== null) {
            throw new GameRuleException("{$show->name} is confirmed. Unlock it to make changes.");
        }

        $blocker = $this->blocker($save, $show);

        if ($blocker !== null) {
            throw new GameRuleException($this->waitsForAiring($save, $show)
                ? "Air {$blocker->name} first. The weekly show is booked once the PPV's results are in."
                : "Book {$blocker->name} first. This week's cards are booked in date order.");
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
            throw new GameRuleException("{$show->name} is the last card of its night. Air it instead.");
        }

        $show->update(['confirmed_at' => now()]);

        return $next;
    }

    /**
     * Reopen a confirmed card, along with every card after it.
     */
    public function unlock(Save $save, Show $show): void
    {
        if ($show->week_number !== $save->current_week || $show->status !== ShowStatus::Booking) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        $steps = $this->steps($save, $show->week_number);
        $from = $steps->search(fn (Show $s): bool => $s->is($show));

        foreach ($steps->slice((int) $from) as $step) {
            if (self::night($step) === self::night($show)) {
                $step->update(['confirmed_at' => null]);
            }
        }
    }

    /**
     * The player's cards of the night that airs next: the PPV night until
     * it has aired, then the weekly show.
     *
     * @return Collection<int, Show>
     */
    public function nightUp(Save $save, int $week): Collection
    {
        $steps = $this->steps($save, $week);
        $ppv = $steps->filter(fn (Show $s): bool => self::night($s) === 'ppv');

        return ($ppv->contains(fn (Show $s): bool => $s->status !== ShowStatus::Aired) ? $ppv : $steps->filter(fn (Show $s): bool => self::night($s) === 'weekly'))->values();
    }

    /**
     * Throws unless every card before the airing night's last one is confirmed.
     */
    public function assertReadyToAir(Save $save, int $week): void
    {
        $steps = $this->nightUp($save, $week);

        foreach ($steps->slice(0, max(0, $steps->count() - 1)) as $step) {
            if ($step->confirmed_at === null) {
                throw new GameRuleException("Book and confirm {$step->name} before it can air.");
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
