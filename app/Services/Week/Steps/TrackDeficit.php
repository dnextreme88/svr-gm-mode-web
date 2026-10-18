<?php

namespace App\Services\Week\Steps;

use App\Enums\EmailTemplate;
use App\Models\SaveBrand;
use App\Services\Mail\EmailService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Counts consecutive weeks below $0 (§7). At the limit the GM must choose
 * how to recover before the next week can air.
 */
class TrackDeficit implements WeekStep
{
    public const CHOICE_PROMOS = 'promos';

    public const CHOICE_CHEAP_MATCHES = 'cheap_matches';

    public const CHOICE_RELEASE = 'release';

    public function __construct(private EmailService $emails) {}

    public function handle(WeekContext $context): void
    {
        $limit = (int) config('gm.finances.deficit_weeks_limit');

        foreach (SaveBrand::where('save_id', $context->save->id)->get() as $brand) {
            if ($brand->budget >= 0) {
                $brand->update(['deficit_weeks' => 0, 'deficit_choice' => null]);

                continue;
            }

            $weeks = $brand->deficit_weeks + 1;
            $brand->update(['deficit_weeks' => $weeks]);

            if ($weeks === $limit) {
                $brand->update(['deficit_choice' => $brand->is_human ? null : self::CHOICE_CHEAP_MATCHES]);

                $this->emails->send(
                    $context->save,
                    $brand->brand,
                    EmailTemplate::Deficit,
                    "We've been in the red for {$weeks} weeks",
                    ['weeks' => $weeks, 'budget' => $brand->budget],
                    'The board wants a plan. Run Advertising or Merchandise promos, downgrade to cheaper match types, or release roster members.',
                    $context->week,
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function choices(): array
    {
        return [self::CHOICE_PROMOS, self::CHOICE_CHEAP_MATCHES, self::CHOICE_RELEASE];
    }
}
