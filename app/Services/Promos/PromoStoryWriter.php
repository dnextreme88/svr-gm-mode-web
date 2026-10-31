<?php

namespace App\Services\Promos;

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\PromoTypeKey;
use App\Enums\RosterType;
use App\Models\Injury;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\PromoSlot;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Factions\FactionNamer;
use App\Services\News\MatchFinish;
use App\Services\News\NewsRecorder;
use App\Services\News\PromoStories;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;

/**
 * Writes the news story for every promo on a show once it has aired (house
 * rule), so a story can tell how the promo played into the night: how the
 * wrestler's match went, how the hyped main event or title match ended.
 * The situation decides the pool of variants in `PromoStories`; one is drawn
 * with equal odds from the seeded RNG.
 */
class PromoStoryWriter
{
    public function __construct(
        private NewsRecorder $news,
        private TitleService $titles,
        private CalendarService $calendar,
        private FactionNamer $factions,
    ) {}

    public function write(Show $show, Rng $rng): void
    {
        $brand = $show->brand;

        if ($brand === null) {
            return;
        }

        $slots = PromoSlot::with(['promoType', 'target.wrestler', 'targetB.wrestler', 'targetChampionship'])
            ->where('show_id', $show->id)
            ->whereNotNull('promo_type_id')
            ->whereNotNull('succeeded')
            ->orderBy('position')
            ->get();

        if ($slots->isEmpty()) {
            return;
        }

        $matches = MatchSlot::with(['matchType', 'result', 'participants.saveWrestler.wrestler', 'titleChampionship'])
            ->where('show_id', $show->id)
            ->orderBy('position')
            ->get()
            ->filter(fn (MatchSlot $s): bool => $s->result !== null)
            ->values();

        foreach ($slots as $slot) {
            $this->writeOne($show, $brand, $slot, $matches, $rng);
        }
    }

    /**
     * @param  Collection<int, MatchSlot>  $matches
     */
    private function writeOne(Show $show, Brand $brand, PromoSlot $slot, Collection $matches, Rng $rng): void
    {
        $type = $slot->promoType;

        if ($type === null) {
            return;
        }

        $rival = $brand === Brand::Raw ? Brand::Smackdown : Brand::Raw;
        $succeeded = (bool) $slot->succeeded;
        $outcome = $succeeded ? 'success' : 'failed';
        $payload = [
            'brand' => $brand->label(),
            'rival_brand' => $rival->label(),
            'show' => $show->name,
            'promo' => $type->name,
            'wrestler' => $slot->target?->name(),
            'wrestler_b' => $slot->targetB?->name(),
            'outcome' => $succeeded ? 'succeeds' : 'fails',
        ];
        $subjects = ['wrestler_ids' => $slot->targetIds()];
        $event = NewsEventType::PromoResult;
        $tier = NewsTier::Story;
        $newsBrand = NewsBrand::from($brand->value);
        $priority = (int) config('gm.news.promo_result_priority');
        $dedupe = null;
        $situations = [$outcome];

        switch ($type->key) {
            case PromoTypeKey::InterviewSpace:
                [$situations, $extra, $dedupe] = $this->interview($slot, $matches, $succeeded);
                $payload += $extra;
                break;

            case PromoTypeKey::MainEventHype:
                $main = $matches->last();

                if ($main === null) {
                    $situations = ['no_match'];
                    $payload['outcome'] = 'fizzles';
                    break;
                }

                [$kind, $extra] = $this->titleOutcome($show, $main, $matches);
                $payload += $extra;
                $situations = [$kind === 'cheap' ? 'title_cheap' : "{$outcome}_".match ($kind) {
                    'change' => 'title_change',
                    'retained' => 'title_retained',
                    default => 'match',
                }];
                $dedupe = "slot:{$main->id}";
                $subjects = ['wrestler_ids' => $main->competitorIds()];

                // A hyped main event that delivers is the brand's headline.
                if ($succeeded) {
                    $event = NewsEventType::MainEventHypeResult;
                    $tier = NewsTier::Headline;
                    $priority = (int) config('gm.news.match_result_priority.hyped_main_event');
                }
                break;

            case PromoTypeKey::TitleMatchHype:
                $titleMatch = $matches->first(fn (MatchSlot $m): bool => $m->title_championship_id !== null && $m->title_championship_id === $slot->target_championship_id);
                $payload['title'] = $slot->targetChampionship?->name;

                if ($titleMatch === null) {
                    $situations = ['fizzles'];
                    $payload['outcome'] = 'fizzles';
                    break;
                }

                [$kind, $extra] = $this->titleOutcome($show, $titleMatch, $matches);
                $payload = array_merge($payload, $extra);
                $situations = [$kind === 'cheap' ? 'title_cheap' : "{$outcome}_".($kind === 'change' ? 'change' : 'retained')];
                $dedupe = "slot:{$titleMatch->id}";
                $subjects = ['wrestler_ids' => $titleMatch->competitorIds()];
                break;

            case PromoTypeKey::InactiveSuperstar:
                if ($slot->target_wrestler_id !== null && $matches->contains(fn (MatchSlot $m): bool => in_array($slot->target_wrestler_id, $m->participantIds(), true))) {
                    $situations = ['fizzles'];
                    $payload['outcome'] = 'fizzles';
                }
                break;

            case PromoTypeKey::ComebackReminder:
                $payload += $this->injuryOf($show, $slot->target);
                break;

            case PromoTypeKey::Advertising:
            case PromoTypeKey::MerchandiseSponsorship:
                $payload['amount'] = '$'.number_format(abs((int) $slot->cost_or_profit));
                break;

            case PromoTypeKey::PpvHype:
                $next = $this->calendar->nextPpv($show->week_number + 1, $brand);
                $weeks = $next === null ? null : $next['week'] - $show->week_number;
                $payload['ppv'] = $next['ppv']->name ?? 'the next pay-per-view';
                $payload['ppv_weeks'] = $weeks === null ? 'weeks' : ($weeks === 1 ? '1 week' : "{$weeks} weeks");
                break;

            case PromoTypeKey::NewSuperstar:
                if ($succeeded) {
                    $event = NewsEventType::Debut;
                    $priority = 30;
                }
                break;

            case PromoTypeKey::SlanderPromotion:
                $situations = [$slot->backfired ? 'backfires' : ($succeeded ? 'succeeds' : 'fails')];
                $payload['outcome'] = $situations[0];
                [$event, $tier, $newsBrand, $priority] = [NewsEventType::SlanderResult, NewsTier::Headline, NewsBrand::Both, 75];
                break;

            case PromoTypeKey::InterferencePromotion:
                $situations = [$slot->backfired ? 'backfires' : ($succeeded ? 'disrupts' : 'fails')];
                $payload['outcome'] = $slot->backfired ? 'embarrasses itself on' : ($succeeded ? 'disrupts' : 'fails to disrupt');
                [$event, $tier, $newsBrand, $priority] = [NewsEventType::InterferencePromoResult, NewsTier::Headline, NewsBrand::Both, 75];
                break;

            default:
                break;
        }

        // Every variant of every situation in play has the same chance.
        $pool = [];

        foreach ($situations as $situation) {
            $key = "{$type->key->value}.{$situation}";

            foreach (array_keys(PromoStories::variants($key)) as $index) {
                $pool[] = [$key, $index];
            }
        }

        if ($pool === []) {
            return;
        }

        [$payload['story'], $payload['variant']] = $rng->pick($pool);

        $this->news->record($show->game, $event, $tier, $newsBrand, $payload, $subjects, priority: $priority, week: $show->week_number, dedupeKey: $dedupe);

        // The hyped main event's headline replaces its plain result.
        if ($event === NewsEventType::MainEventHypeResult) {
            NewsEvent::where('save_id', $show->save_id)->where('type', NewsEventType::MatchResult)->where('dedupe_key', $dedupe)->delete();
        }
    }

    /**
     * Interview Space: the wrestler's match that night decides the story.
     * Not booked, a successful interview can also be about their form.
     *
     * @param  Collection<int, MatchSlot>  $matches
     * @return array{0: list<string>, 1: array<string, mixed>, 2: string|null}
     */
    private function interview(PromoSlot $slot, Collection $matches, bool $succeeded): array
    {
        $wrestler = $slot->target;
        $outcome = $succeeded ? 'success' : 'failed';

        if ($wrestler === null) {
            return [[$succeeded ? 'unbooked' : 'unbooked_flat'], [], null];
        }

        $match = $this->competedIn($matches, $wrestler->id);

        if ($match !== null) {
            $result = $match->result;
            $won = $match->competitors()->contains(fn (MatchParticipant $p): bool => $p->save_wrestler_id === $wrestler->id && $p->side === $result?->winner_side);
            $opponents = $match->competitors()
                ->filter(fn (MatchParticipant $p): bool => $won ? $p->side !== $result?->winner_side : $p->side === $result?->winner_side)
                ->map(fn (MatchParticipant $p): int => $p->save_wrestler_id)
                ->values()
                ->all();

            return [[$outcome.($won ? '_won' : '_lost')], ['opponent' => $this->sideNames($match, $opponents), 'how' => MatchFinish::phrase($match)], "slot:{$match->id}"];
        }

        // Not booked and it failed: crashed by another superstar if the promo
        // found one (PromoService::crashInterview), else it just fell flat.
        if (! $succeeded) {
            $crasher = $slot->targetB;

            if ($crasher === null) {
                return [['unbooked_flat'], [], null];
            }

            // The promo notes whether the attack started a rivalry.
            $rivalry = str_ends_with((string) $slot->outcome_note, PromoService::CRASH_RIVALRY_NOTE);

            return [['crashed'], ['rivalry' => $rivalry ? ' The attack has started a rivalry between the two.' : ''], null];
        }

        $situations = ['unbooked'];
        $extra = [];
        $streakFrom = (int) config('gm.news.promo_story_streak_from');
        $reign = $this->titles->reignsHeldBy($wrestler)->first();

        if ($wrestler->win_streak >= $streakFrom) {
            $situations[] = 'unbooked_win_streak+';
            $extra['streak'] = $wrestler->win_streak;
        } elseif ($wrestler->loss_streak >= $streakFrom) {
            $situations[] = 'unbooked_loss_streak+';
            $extra['streak'] = $wrestler->loss_streak;
        }

        if ($reign !== null) {
            $situations[] = 'unbooked_champion+';
            $extra['title'] = $reign->championship->name;
        } elseif ($wrestler->weeks_since_title_match >= (int) ((array) config('gm.morale.title_match'))[0]) {
            $situations[] = 'unbooked_title_shot+';
            $extra['weeks'] = $wrestler->weeks_since_title_match;
        }

        // Nothing about their form to talk about: the spotlight interview.
        if ($situations === ['unbooked']) {
            return [['spotlight'], ['pronoun' => $wrestler->wrestler->roster_type === RosterType::Diva ? 'she' : 'he'], null];
        }

        return [$situations, $extra, null];
    }

    /**
     * How a match went, for the hyped main event or title match: `change`
     * (new champion), `retained` (the champion won), `cheap` (the challenger
     * won by DQ or count-out, so the title stayed) or `match` (no title).
     *
     * @param  Collection<int, MatchSlot>  $matches
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function titleOutcome(Show $show, MatchSlot $match, Collection $matches): array
    {
        $result = $match->result;
        $winnerSide = $result?->winner_side;
        $names = fn (bool $winners): string => $this->sideNames($match, $match->competitors()
            ->filter(fn (MatchParticipant $p): bool => ($p->side === $winnerSide) === $winners)
            ->map(fn (MatchParticipant $p): int => $p->save_wrestler_id)
            ->values()
            ->all());
        $payload = [
            'winner' => $names(true),
            'loser' => $names(false),
            'how' => MatchFinish::phrase($match),
            'where' => $this->where($match, $matches),
        ];

        $championship = $match->titleChampionship;

        if ($championship === null) {
            return ['match', $payload];
        }

        $payload['title'] = $championship->name;

        if ($result?->title_changed) {
            return ['change', $payload];
        }

        $holders = $this->titles->currentReign($show->game, $championship)?->holderIds() ?? [];
        $winners = $match->competitors()->filter(fn (MatchParticipant $p): bool => $p->side === $winnerSide)->pluck('save_wrestler_id')->all();

        if (array_intersect($winners, $holders) !== []) {
            return ['retained', $payload];
        }

        return ['cheap', $payload + ['champion' => $payload['loser'], 'challenger' => $payload['winner']]];
    }

    /**
     * Names joined with "&", factions named as such (house rule).
     *
     * @param  array<int, int>  $ids
     */
    private function sideNames(MatchSlot $match, array $ids): string
    {
        return $this->factions->name($match->show->save_id, $ids, $match->show->week_number, $match->sides());
    }

    /**
     * "in the main event", "to open the show" or "in match N".
     *
     * @param  Collection<int, MatchSlot>  $matches
     */
    private function where(MatchSlot $match, Collection $matches): string
    {
        return match (true) {
            $match->is($matches->last()) => 'in the main event',
            $match->is($matches->first()) => 'to open the show',
            default => "in match {$match->position}",
        };
    }

    /**
     * @param  Collection<int, MatchSlot>  $matches
     */
    private function competedIn(Collection $matches, int $wrestlerId): ?MatchSlot
    {
        return $matches->first(fn (MatchSlot $m): bool => in_array($wrestlerId, $m->competitorIds(), true));
    }

    /**
     * The serious injury a Comeback Reminder is about.
     *
     * @return array{injury: string, weeks_left: string}
     */
    private function injuryOf(Show $show, ?SaveWrestler $wrestler): array
    {
        $injury = $wrestler === null ? null : Injury::with('type')
            ->where('save_wrestler_id', $wrestler->id)
            ->where('severity', InjurySeverity::Serious)
            ->whereNull('healed_week')
            ->latest('id')
            ->first();
        $weeks = $injury === null ? null : max(1, $injury->ends_week - $show->week_number);

        return [
            'injury' => $injury === null ? 'serious injury' : mb_strtolower($injury->type->label),
            'weeks_left' => $weeks === null ? 'a few weeks' : ($weeks === 1 ? '1 week' : "{$weeks} weeks"),
        ];
    }
}
