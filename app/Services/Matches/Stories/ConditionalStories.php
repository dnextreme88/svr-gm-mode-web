<?php

namespace App\Services\Matches\Stories;

use App\Models\ConditionalStoryTrigger;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\Rivalry;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Matches\ResolvedMatch;
use App\Services\News\NewsRecorder;
use App\Services\Week\WeekContext;
use Illuminate\Support\Collection;

/**
 * Conditional match stories (house rule, 2026-11-07), run by ShowResolver
 * around each simulated match (never Book to Win):
 *
 * - `before()` picks at most one story that changes the result, in
 *   `gm.stories.order` (Undertaker, Kane, Dudleys, Hardys).
 * - `after()` records it, and Hornswoggle's shillelagh (story only), adds
 *   each story's text to the match's news summary, and keeps it for the
 *   live results screen's pop-up.
 */
class ConditionalStories
{
    /** @var array<string, ConditionalStoryRule> */
    private array $rules;

    public function __construct(
        UndertakerLightsOut $lightsOut,
        KanePyro $pyro,
        DudleyTables $tables,
        HardyLadder $ladder,
        private HornswoggleShillelagh $shillelagh,
        private NewsRecorder $news,
        private CalendarService $calendar,
    ) {
        $this->rules = [];

        foreach ([$lightsOut, $pyro, $tables, $ladder] as $rule) {
            $this->rules[$rule->story()->value] = $rule;
        }
    }

    /**
     * The story that will decide this match, if one applies.
     *
     * @param  Collection<int, Rivalry>  $rivalries  active going into the match
     * @param  list<int|null>  $bookedBouts
     */
    public function before(MatchSlot $slot, Show $show, WeekContext $context, Collection $rivalries, ?int $bookedSide, array $bookedBouts = []): ?StoryPlan
    {
        if ($this->booked($bookedSide, $bookedBouts)) {
            return null;
        }

        $match = new StoryMatch($slot, $show, $context, $rivalries);

        foreach ((array) config('gm.stories.order') as $story) {
            $plan = ($this->rules[$story] ?? null)?->before($match);

            if ($plan !== null) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Record what happened: the planned story, and Hornswoggle's shillelagh.
     *
     * @param  Collection<int, Rivalry>  $rivalries
     * @param  list<int|null>  $bookedBouts
     */
    public function after(MatchSlot $slot, Show $show, WeekContext $context, Collection $rivalries, ResolvedMatch $resolved, ?StoryPlan $plan, ?int $bookedSide, array $bookedBouts = []): void
    {
        if ($this->booked($bookedSide, $bookedBouts)) {
            return;
        }

        $match = new StoryMatch($slot, $show, $context, $rivalries);
        $played = [];

        if ($plan !== null && isset($this->rules[$plan->story->value])) {
            $rule = $this->rules[$plan->story->value];
            $played[] = [$rule, $rule->after($match, $resolved, $plan)];
        }

        $played[] = [$this->shillelagh, $this->shillelagh->after($match, $resolved, null)];

        foreach ($played as [$rule, $done]) {
            if ($done !== null) {
                $this->record($match, $resolved, $rule, $done);
            }
        }
    }

    private function record(StoryMatch $match, ResolvedMatch $resolved, ConditionalStoryRule $rule, StoryPlan $plan): void
    {
        $text = $rule->text($match, $resolved, $plan);
        $save = $match->save();

        ConditionalStoryTrigger::create([
            'save_id' => $save->id,
            'story' => $plan->story,
            'outcome' => $plan->outcome,
            'season' => $this->calendar->seasonOf($match->week()),
            'week_number' => $match->week(),
            'match_slot_id' => $match->slot->id,
            'rivalry_id' => $plan->rivalryId,
            'save_faction_id' => $plan->factionId,
            'tokens' => [
                'title' => $text->title,
                'text' => $text->text,
                'what_changed' => $text->whatChanged,
                'next_label' => $text->nextLabel,
                'next' => $text->next,
            ],
            // The pop-up is for the player's live results: their own cards and
            // the interbrand ones. Simulate to here and the rival's own shows
            // never pop up.
            'seen_at' => $this->showsPopUp($match) ? null : now(),
        ]);

        $this->tagNews($match, $plan, $text);
    }

    private function showsPopUp(StoryMatch $match): bool
    {
        return isset($match->context->data['phase'])
            && ($match->show->brand === null || $match->show->brand === $match->save()->player_brand);
    }

    /**
     * Add the story to every news item about this match (summary only).
     */
    private function tagNews(StoryMatch $match, StoryPlan $plan, StoryText $text): void
    {
        $id = $match->slot->id;

        NewsEvent::where('save_id', $match->save()->id)
            ->where('week_number', $match->week())
            ->whereIn('dedupe_key', ["slot:{$id}", "ppv-main:{$id}", "faction-interbrand:{$id}"])
            ->get()
            ->each(function (NewsEvent $event) use ($plan, $text): void {
                $payload = $event->payload;
                $asides = array_values(array_filter([...(array) ($payload['asides'] ?? []), $plan->story->value]));
                $payload['asides'] = $asides;
                $payload['aside_text'] = trim(((string) ($payload['aside_text'] ?? '')).' '.$text->text);
                $this->news->rewrite($event, $payload);
            });
    }

    /**
     * @param  list<int|null>  $bookedBouts
     */
    private function booked(?int $bookedSide, array $bookedBouts): bool
    {
        // Switched off (tests that script results), or a Book to Win match.
        return ! config('gm.stories.enabled')
            || $bookedSide !== null
            || array_filter($bookedBouts, fn (?int $side): bool => $side !== null) !== [];
    }
}
