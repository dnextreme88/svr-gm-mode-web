<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\Morale;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ParticipantRole;
use App\Enums\ShowStatus;
use App\Models\Contract;
use App\Models\Injury;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Services\Calendar\CalendarService;
use App\Services\Factions\FactionNamer;
use App\Services\News\FallbackStories;
use App\Services\News\MatchFinish;
use App\Services\News\NewsComposer;
use App\Services\News\NewsRecorder;
use App\Services\Titles\TitleService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;

/**
 * House rule: the News page is never empty. Once the week's real news is
 * in, any card it leaves empty gets filler (written now, so an old week's
 * page shows that week's champions, budget and injuries):
 *
 * - the WWE headline: tickets for whatever airs next;
 * - a brand headline: its top champion's week on the road (or the roster at
 *   WWE HQ), plus a word on the budget;
 * - brand stories: a superstar's last match, then the injury report;
 * - the ticker: locker-room morale and contracts.
 */
class QuietWeekNews implements WeekStep
{
    public function __construct(
        private NewsComposer $composer,
        private NewsRecorder $news,
        private CalendarService $calendar,
        private TitleService $titles,
        private FactionNamer $factions,
    ) {}

    public function handle(WeekContext $context): void
    {
        $save = $context->save;
        $week = $context->week;
        $page = $this->composer->compose($save, $week);
        $rng = $context->rng('news.quiet-week');

        if ($page['headlines']['neutral'] === null) {
            $this->tickets($context, $rng);
        }

        foreach (Brand::cases() as $brand) {
            $roster = SaveWrestler::with('wrestler')->where('save_id', $save->id)->where('brand', $brand)->orderBy('id')->get();
            $missing = (int) config('gm.news.stories_per_brand') - count($page['stories'][$brand->value]);

            if ($page['headlines'][$brand->value] === null) {
                $this->spotlight($context, $brand, $rng);
            }

            if ($missing >= 1 && $roster->isNotEmpty()) {
                $this->recap($context, $brand, $roster, $rng);
            }

            if ($missing >= 2) {
                $this->injuryReport($context, $brand, $roster, $rng);
            }

            if ($page['ticker'] === [] && $roster->isNotEmpty()) {
                $this->lockerRoom($context, $brand, $roster, $rng);
            }
        }
    }

    /**
     * Whatever airs next: next week's PPV (Sunday comes first), else both
     * weekly shows, Raw and Smackdown, each with its date.
     */
    private function tickets(WeekContext $context, Rng $rng): void
    {
        $next = $context->week + 1;
        $ppv = $this->calendar->ppvsForWeek($next)->first();

        if ($ppv !== null) {
            $this->record($context, NewsEventType::TicketsOnSale, NewsTier::Headline, NewsBrand::Both, 'tickets.ppv', [
                'event' => $ppv->name,
                'date' => $this->calendar->date($next)->format('D M j, Y'),
            ], $rng);

            return;
        }

        $this->record($context, NewsEventType::TicketsOnSale, NewsTier::Headline, NewsBrand::Both, 'tickets.shows', [
            'event' => 'Monday Night Raw and Friday Night Smackdown',
            'raw_date' => $this->calendar->showDate($next, Brand::Raw)->format('F j, Y'),
            'smackdown_date' => $this->calendar->showDate($next, Brand::Smackdown)->format('F j, Y'),
        ], $rng);
    }

    /**
     * The brand's top champion (world, then second tier, tag, special)
     * spent the week with the fans; no champions means a week at WWE HQ.
     * Either way, a word on the budget.
     */
    private function spotlight(WeekContext $context, Brand $brand, Rng $rng): void
    {
        $reign = $this->titles->currentReigns($context->save)
            ->filter(fn (TitleReign $r): bool => $r->championship->brand === $brand)
            ->sortBy(fn (TitleReign $r): int => $r->championship->sort_order)
            ->first();
        $budget = $context->save->brand($brand)->budget;
        $healthy = $budget > (int) config('gm.emails.low_cash_threshold');
        $line = $rng->pick(FallbackStories::variants($healthy ? 'budget.good' : 'budget.bad'))[1];
        $payload = [
            'brand' => $brand->label(),
            'budget' => strtr($line, ['{brand}' => $brand->label(), '{money}' => '$'.number_format($budget)]),
            'budget_ok' => $healthy,
        ];

        if ($reign !== null) {
            $payload += [
                'champion' => $reign->holderName(),
                'title' => $reign->championship->name,
                'city' => (string) $rng->pick((array) config('gm.news.tour_cities')),
            ];
        }

        $this->record($context, NewsEventType::BrandSpotlight, NewsTier::Headline, NewsBrand::from($brand->value), $reign !== null ? 'spotlight.champion' : 'spotlight.hq', $payload, $rng);
    }

    /**
     * A random superstar's most recent match.
     *
     * @param  Collection<int, SaveWrestler>  $roster
     */
    private function recap(WeekContext $context, Brand $brand, Collection $roster, Rng $rng): void
    {
        $last = MatchParticipant::with(['matchSlot.show', 'matchSlot.result', 'matchSlot.matchType', 'matchSlot.participants.saveWrestler.wrestler'])
            ->whereIn('save_wrestler_id', $roster->pluck('id')->all())
            ->where('role', ParticipantRole::Competitor)
            ->whereNotNull('result')
            ->whereHas('matchSlot.show', fn ($q) => $q->where('status', ShowStatus::Aired)->where('week_number', '<=', $context->week))
            ->get()
            ->groupBy('save_wrestler_id')
            ->map(fn (Collection $rows): MatchParticipant => $rows->sortByDesc(fn (MatchParticipant $p): int => $p->matchSlot->show->week_number * 100 + $p->matchSlot->position)->first());

        if ($last->isEmpty()) {
            $wrestler = $rng->pick($roster->values()->all());
            $this->record($context, NewsEventType::SuperstarRecap, NewsTier::Story, NewsBrand::from($brand->value), 'recap.none', [
                'brand' => $brand->label(),
                'wrestler' => $wrestler->name(),
            ], $rng, [$wrestler->id]);

            return;
        }

        /** @var MatchParticipant $p */
        $p = $rng->pick($last->values()->all());
        $slot = $p->matchSlot;
        $won = $p->result === 'win';
        $weeksAgo = $context->week - $slot->show->week_number;

        $this->record($context, NewsEventType::SuperstarRecap, NewsTier::Story, NewsBrand::from($brand->value), $won ? 'recap.won' : 'recap.lost', [
            'brand' => $brand->label(),
            'wrestler' => $p->saveWrestler->name(),
            'opponent' => $this->opponents($slot, $p),
            'how' => MatchFinish::phrase($slot),
            'show' => $slot->show->name,
            'when' => match ($weeksAgo) {
                0 => 'this week',
                1 => 'last week',
                default => "{$weeksAgo} weeks ago",
            },
        ], $rng, [$p->save_wrestler_id]);
    }

    /**
     * Injuries on the roster in the last ~6 months: praise, or advice.
     *
     * @param  Collection<int, SaveWrestler>  $roster
     */
    private function injuryReport(WeekContext $context, Brand $brand, Collection $roster, Rng $rng): void
    {
        $injuries = Injury::with(['type', 'saveWrestler.wrestler'])
            ->whereIn('save_wrestler_id', $roster->pluck('id')->all())
            ->where('started_week', '>', $context->week - (int) config('gm.news.injury_report_weeks'))
            ->where('started_week', '<=', $context->week)
            ->orderByDesc('started_week')
            ->orderByDesc('id')
            ->get();
        $latest = $injuries->first();

        $this->record($context, NewsEventType::InjuryReport, NewsTier::Story, NewsBrand::from($brand->value), $latest === null ? 'injuries.clean' : 'injuries.hurt', [
            'brand' => $brand->label(),
            'count' => $injuries->count(),
            'wrestler' => $latest?->saveWrestler->name(),
            'injury' => $latest === null ? null : mb_strtolower($latest->type->label),
        ], $rng);
    }

    /**
     * Ticker notes: unhappy wrestlers, contracts about to run out, or calm.
     *
     * @param  Collection<int, SaveWrestler>  $roster
     */
    private function lockerRoom(WeekContext $context, Brand $brand, Collection $roster, Rng $rng): void
    {
        $newsBrand = NewsBrand::from($brand->value);
        $names = fn (Collection $wrestlers): string => $wrestlers->take(3)->map(fn (SaveWrestler $w): string => $w->name())->implode(', ');
        $unhappy = $roster->filter(fn (SaveWrestler $w): bool => in_array($w->morale, [Morale::Unsatisfied, Morale::Upset], true))->values();
        $weeks = (int) config('gm.contracts.expiring_threshold_weeks');
        $expiring = Contract::with('saveWrestler.wrestler')
            ->whereIn('save_wrestler_id', $roster->pluck('id')->all())
            ->where('status', ContractStatus::Active)
            ->where('weeks_remaining', '<=', $weeks)
            ->orderBy('weeks_remaining')
            ->get()
            ->map(fn (Contract $c): SaveWrestler => $c->saveWrestler);

        if ($unhappy->isNotEmpty()) {
            $this->record($context, NewsEventType::LockerRoomNote, NewsTier::Ticker, $newsBrand, 'locker.unhappy', ['brand' => $brand->label(), 'names' => $names($unhappy)], $rng);
        }

        if ($expiring->isNotEmpty()) {
            $this->record($context, NewsEventType::LockerRoomNote, NewsTier::Ticker, $newsBrand, 'locker.expiring', ['brand' => $brand->label(), 'names' => $names($expiring), 'n' => $weeks], $rng);
        }

        if ($unhappy->isEmpty() && $expiring->isEmpty()) {
            $this->record($context, NewsEventType::LockerRoomNote, NewsTier::Ticker, $newsBrand, 'locker.calm', ['brand' => $brand->label()], $rng);
        }
    }

    private function opponents(MatchSlot $slot, MatchParticipant $p): string
    {
        $won = $p->result === 'win';

        $ids = $slot->competitors()
            ->filter(fn (MatchParticipant $o): bool => $won ? $o->side !== $p->side : $o->side === $slot->result?->winner_side)
            ->map(fn (MatchParticipant $o): int => $o->save_wrestler_id)
            ->values()
            ->all();

        // Factions are named as such (house rule).
        return $this->factions->name($slot->show->save_id, $ids, $slot->show->week_number, $slot->sides());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $wrestlerIds
     */
    private function record(WeekContext $context, NewsEventType $type, NewsTier $tier, NewsBrand $brand, string $story, array $payload, Rng $rng, array $wrestlerIds = []): void
    {
        $variants = FallbackStories::variants($story);
        $payload['story'] = $story;
        $payload['variant'] = (int) $rng->pick(array_keys($variants));

        // Lowest priority: filler never outranks real news.
        $this->news->record($context->save, $type, $tier, $brand, $payload, ['wrestler_ids' => $wrestlerIds], priority: 1, week: $context->week);
    }
}
