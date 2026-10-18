<?php

namespace App\Services\Week\Steps;

use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Services\News\NewsRecorder;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Reign milestones (§19) and Money in the Bank expiry (§10).
 */
class TitleUpkeep implements WeekStep
{
    public function __construct(
        private TitleService $titles,
        private MitbService $mitb,
        private NewsRecorder $news,
    ) {}

    public function handle(WeekContext $context): void
    {
        $milestones = (array) config('gm.news.reign_milestones');

        foreach ($this->titles->currentReigns($context->save) as $reign) {
            $held = $reign->weeksHeld($context->week + 1);

            if (in_array($held, $milestones, true)) {
                $this->news->record($context->save, NewsEventType::ReignMilestone, NewsTier::Story, $reign->brand, [
                    'champion' => $reign->holderName(),
                    'weeks' => $held,
                    'title' => $reign->championship->name,
                ], ['championship_id' => $reign->championship_id, 'wrestler_ids' => $reign->holderIds()], priority: 30, week: $context->week);
            }
        }

        $this->mitb->expire($context->save, $context->week);
    }
}
