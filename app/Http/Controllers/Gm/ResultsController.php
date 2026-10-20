<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CardPresenter;
use App\Models\Email;
use App\Models\FatigueLog;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The week's results (§20) for one brand, in air order (the Sunday PPV and
 * interbrand card, then the weekly show), with each wrestler's fatigue
 * through the week. `?brand=` views the rival's shows; `?show=` opens that
 * show's first result (both come from the Schedule calendar).
 */
class ResultsController extends Controller
{
    public function __construct(private CalendarService $calendar) {}

    public function __invoke(Request $request, Save $save, int $week): Response
    {
        $brand = Brand::tryFrom((string) $request->query('brand')) ?? $save->player_brand;
        $logs = FatigueLog::where('save_id', $save->id)->where('week_number', $week)->get()->keyBy('save_wrestler_id');

        $shows = Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('week_number', $week)
            ->where(fn ($q) => $q->where('brand', $brand)->orWhereNull('brand'))
            ->where('status', ShowStatus::Aired)
            ->get()
            ->sortBy(fn (Show $s): int => $s->isInterbrand() ? 1 : ($s->isPpv() ? 0 : 2))
            ->map(function (Show $show) use ($logs): array {
                $slots = MatchSlot::with(['matchType', 'participants.saveWrestler.wrestler', 'result', 'titleChampionship'])
                    ->where('show_id', $show->id)
                    ->whereHas('result')
                    ->orderBy('position')
                    ->get();

                return [
                    ...CardPresenter::show($show),
                    'outcomes' => $slots->map(fn (MatchSlot $slot): array => CardPresenter::outcome($slot))->values(),
                    'fatigue' => $this->fatigue($show, $slots, $logs),
                ];
            })
            ->values();

        return Inertia::render('gm/results', [
            'week_number' => $week,
            'season_week' => $this->calendar->seasonWeek($week),
            'brand' => $brand->value,
            'focus_show_id' => $request->integer('show') ?: null,
            'from_schedule' => $request->has('show'),
            'shows' => $shows,
            'new_emails' => Email::where('save_id', $save->id)->where('brand', $save->player_brand)->where('week_number', $week)->count(),
        ]);
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

        return array_values(array_filter(array_map(function (int $id) use ($logs, $wrestlers, $rested): ?array {
            $log = $logs->get($id);
            $wrestler = $wrestlers->get($id);

            if ($log === null || $wrestler === null) {
                return null;
            }

            return [
                'id' => $id,
                'name' => $wrestler->name(),
                'roster_type' => $wrestler->wrestler->roster_type->value,
                'weight_class' => $wrestler->wrestler->weight_class->value,
                'start' => $log->start,
                'after_shows' => $log->after_shows,
                'end' => $log->end,
                'recovered_bars' => $log->recovered_bars,
                'rested' => isset($rested[$id]),
            ];
        }, $ids)));
    }
}
