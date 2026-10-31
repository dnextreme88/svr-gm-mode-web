<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CardPresenter;
use App\Models\Email;
use App\Models\FatigueLog;
use App\Models\Injury;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Matches\ShowResolver;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Services\Week\Steps\AirShows;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The week's results (§20) for one brand, in air order (the Sunday PPV and
 * interbrand card, then the weekly show), with each wrestler's fatigue
 * through the week and the promos where they aired. `?brand=` views the
 * rival's shows; `?show=` opens that show's first result (both come from
 * the Schedule calendar); `?match=` opens that match (from Title History).
 *
 * While the week airs live, the player's undecided matches come through as
 * pending, the next one ready for Simulate or Book to Win.
 */
class ResultsController extends Controller
{
    public function __construct(
        private CalendarService $calendar,
        private WeekResolver $resolver,
        private AirShows $airShows,
        private ShowResolver $showResolver,
        private TitleService $titles,
        private MitbService $mitb,
    ) {}

    public function __invoke(Request $request, Save $save, int $week): Response
    {
        $brand = Brand::tryFrom((string) $request->query('brand')) ?? $save->player_brand;
        $logs = FatigueLog::where('save_id', $save->id)->where('week_number', $week)->get()->keyBy('save_wrestler_id');
        $current = $week === $save->current_week;
        // After the PPV night (house rule, 2026-11-01) the week waits for the
        // weekly show: Continue opens it.
        $awaitingWeekly = $current && $this->resolver->awaitingWeekly($save);
        $airing = $current && ! $awaitingWeekly && $this->resolver->airing($save) !== null;
        $nextId = $airing ? $this->nextMatchId($save, $week) : null;

        $shows = Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('week_number', $week)
            ->where(fn ($q) => $q->where('brand', $brand)->orWhereNull('brand'))
            ->whereIn('status', [ShowStatus::Aired, ShowStatus::Airing])
            ->get()
            ->sortBy(fn (Show $s): int => $s->isInterbrand() ? 1 : ($s->isPpv() ? 0 : 2))
            ->map(function (Show $show) use ($save, $logs, $nextId): array {
                $slots = MatchSlot::with(['matchType', 'participants.saveWrestler.wrestler', 'result.championReign', 'titleChampionship', 'gauntletBouts'])
                    ->where('show_id', $show->id)
                    ->orderBy('position')
                    ->get()
                    ->filter(fn (MatchSlot $slot): bool => $slot->result !== null || ($show->status === ShowStatus::Airing && $slot->isBooked()))
                    ->values();
                $decided = $slots->filter(fn (MatchSlot $slot): bool => $slot->result !== null);

                return [
                    ...CardPresenter::show($show),
                    'outcomes' => $slots->map(fn (MatchSlot $slot): array => $slot->result !== null
                        ? CardPresenter::outcome($slot)
                        : [
                            ...CardPresenter::pending($slot, $slot->id === $nextId, $this->champions($save, $slot)),
                            // House rule: the briefcase can be cashed in during the match up next.
                            'cash_in_holder' => $slot->id === $nextId ? $this->mitb->duringOption($save, $slot, $save->current_week)?->holder->name() : null,
                        ])->values(),
                    'promos' => $this->promos($show),
                    'fatigue' => $this->fatigue($show, $decided, $logs),
                    // House rule (2026-11-01): the crowd, once the night has aired.
                    'attendance' => $show->attendance,
                    'attendance_breakdown' => $show->attendance_breakdown,
                ];
            })
            ->values();

        return Inertia::render('gm/results', [
            'week_number' => $week,
            'season_week' => $this->calendar->seasonWeek($week),
            'brand' => $brand->value,
            'focus_show_id' => $request->integer('show') ?: null,
            'focus_match_id' => $request->integer('match') ?: null,
            'from_schedule' => $request->has('show'),
            'airing' => $airing,
            'cash_in_offer' => $airing ? $this->cashInOffer($save) : null,
            'continue_to' => $awaitingWeekly ? $this->weeklyCard($save, $week) : null,
            'shows' => $shows,
            'new_emails' => Email::where('save_id', $save->id)->where('brand', $save->player_brand)->where('week_number', $week)->count(),
        ]);
    }

    /**
     * The player's weekly show, booked once the PPV night has aired.
     *
     * @return array{name: string, url: string}|null
     */
    private function weeklyCard(Save $save, int $week): ?array
    {
        $show = Show::where('save_id', $save->id)->where('week_number', $week)->where('brand', $save->player_brand)->where('show_type', ShowType::Weekly)->first();

        return $show === null ? null : ['name' => $show->name, 'url' => route('gm.card.show', [$save, $show])];
    }

    /**
     * House rule: after a world title match the player's briefcase holder
     * may cash in; the week waits for the answer.
     *
     * @return array{holder: string, champion: string, title: string}|null
     */
    private function cashInOffer(Save $save): ?array
    {
        $slot = $this->resolver->cashInOffer($save);
        $briefcase = $slot === null ? null : $this->mitb->afterOption($save, $slot, $save->current_week);
        $reign = $briefcase === null ? null : $this->titles->currentReign($save, $this->mitb->worldTitle($briefcase->brand));

        return $briefcase === null || $reign === null ? null : [
            'holder' => $briefcase->holder->name(),
            'champion' => $reign->holderName(),
            'title' => $reign->championship->name,
        ];
    }

    private function nextMatchId(Save $save, int $week): ?int
    {
        $show = $this->airShows->current(new WeekContext($save, $week, $this->calendar->seasonOf($week)));

        return $show === null ? null : $this->showResolver->nextMatch($show)?->id;
    }

    /**
     * Who holds the title on the line going into an undecided match.
     *
     * @return list<string>
     */
    private function champions(Save $save, MatchSlot $slot): array
    {
        $reign = $slot->titleChampionship === null ? null : $this->titles->currentReign($save, $slot->titleChampionship);

        return $reign === null || $reign->isVacant() ? [] : $reign->holder_snapshot['names'];
    }

    /**
     * The show's promos, each placed before the match it aired in front of.
     *
     * @return array<int, array<string, mixed>>
     */
    private function promos(Show $show): array
    {
        $mainEvent = $show->matchSlotCount();

        return PromoSlot::with(['promoType', 'target.wrestler', 'targetB.wrestler', 'targetChampionship'])
            ->where('show_id', $show->id)
            ->whereNotNull('promo_type_id')
            ->orderBy('position')
            ->get()
            ->map(fn (PromoSlot $promo): array => [
                'position' => $promo->position,
                'before_position' => $promo->before_position ?? $mainEvent,
                'type' => $promo->promoType?->name,
                'status' => $promo->status(),
                // Title Match Hype: the title it hyped.
                'title' => $promo->targetChampionship?->name,
                'targets' => collect([$promo->target, $promo->targetB])->filter()->map(fn (SaveWrestler $w): array => [
                    'id' => $w->id,
                    'name' => $w->name(),
                    'roster_type' => $w->wrestler->roster_type->value,
                    'weight_class' => $w->wrestler->weight_class->value,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Everyone who appeared on the show (matches and promos), in card order,
     * with their fatigue going in, after the show, and after recovery.
     *
     * @param  Collection<int, MatchSlot>  $slots
     * @param  Collection<int, FatigueLog>  $logs
     * @return list<array<string, mixed>>
     */
    private function fatigue(Show $show, Collection $slots, Collection $logs): array
    {
        $ids = $slots->flatMap(fn (MatchSlot $s): array => $s->participantIds())->all();
        $promos = PromoSlot::with('promoType')->where('show_id', $show->id)->whereNotNull('promo_type_id')->orderBy('position')->get();
        $rested = [];

        foreach ($promos as $promo) {
            foreach ($promo->targetIds() as $id) {
                $ids[] = $id;

                if ($promo->promoType?->key === PromoTypeKey::InactiveSuperstar && $promo->succeeded) {
                    $rested[$id] = true;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        $wrestlers = SaveWrestler::with('wrestler')->whereIn('id', $ids)->get()->keyBy('id');
        // This week's injuries: they replace the after-recovery fatigue.
        $injuries = Injury::with('type')
            ->whereIn('save_wrestler_id', $ids)
            ->where('started_week', $show->week_number)
            ->orderBy('id')
            ->get()
            ->keyBy('save_wrestler_id');

        return array_values(array_filter(array_map(function (int $id) use ($logs, $wrestlers, $rested, $injuries): ?array {
            $log = $logs->get($id);
            $wrestler = $wrestlers->get($id);
            $injury = $injuries->get($id);

            if ($log === null || $wrestler === null) {
                return null;
            }

            return [
                'id' => $id,
                'name' => $wrestler->name(),
                'roster_type' => $wrestler->wrestler->roster_type->value,
                'weight_class' => $wrestler->wrestler->weight_class->value,
                'start' => $log->start,
                // Uncapped when an injury came from going over 100%.
                'after_shows' => max($log->after_shows, $injury->fatigue_after ?? 0),
                'end' => $log->end,
                'recovered_bars' => $log->recovered_bars,
                'rested' => isset($rested[$id]),
                'injury' => $injury === null ? null : [
                    'severity' => $injury->severity->value,
                    'label' => $injury->type->label,
                ],
            ];
        }, $ids)));
    }
}
