<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\MitbStatus;
use App\Enums\SaveStage;
use App\Models\MoneyInTheBank;
use App\Models\Season;
use App\Models\Show;
use App\Services\Mail\EmailService;
use App\Services\Ppv\InterbrandSeriesService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * After WrestleMania (§13, §23): score the brand battle, expire last year's
 * briefcases, close the season and open the re-draft. The budget carries
 * over; there is no new $15M grant.
 */
class WrestleManiaWrapUp implements WeekStep
{
    public function __construct(
        private InterbrandSeriesService $series,
        private EmailService $emails,
    ) {}

    public function handle(WeekContext $context): void
    {
        $show = Show::with('ppvEvent')->where('save_id', $context->save->id)->where('week_number', $context->week)->whereNull('brand')->get()
            ->first(fn (Show $s): bool => $s->isWrestleMania());

        if ($show === null) {
            return;
        }

        $this->series->settle($show);

        // A briefcase expires when the next WrestleMania resolves (§10). The
        // one won tonight is next year's.
        MoneyInTheBank::where('save_id', $context->save->id)
            ->where('status', MitbStatus::Held)
            ->where('won_week', '<', $context->week)
            ->update(['status' => MitbStatus::Expired, 'resolved_week' => $context->week, 'resolution_note' => 'wrestlemania']);

        Season::where('save_id', $context->save->id)->where('number', $context->season)->update(['ended_week' => $context->week]);

        Season::firstOrCreate(
            ['save_id' => $context->save->id, 'number' => $context->season + 1],
            ['started_week' => $context->week + 1, 'starting_budget' => null],
        );

        // House rule (2026-11-15): the re-draft's own WWE headline
        // (`redraft_results`) replaces the old season_reset news.
        $context->save->update(['stage' => SaveStage::Redrafting]);

        foreach (Brand::cases() as $brand) {
            $this->emails->draftReminder($context->save, $brand, $context->week);
        }
    }
}
