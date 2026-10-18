<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\Save;
use App\Models\Show;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The match outcome screen after a week airs (§20).
 */
class ResultsController extends Controller
{
    public function __invoke(Save $save, int $week): Response
    {
        $shows = Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('week_number', $week)
            ->where(fn ($q) => $q->where('brand', $save->player_brand)->orWhereNull('brand'))
            ->get()
            ->sortBy(fn (Show $s): int => $s->isPpv() ? 1 : 0)
            ->map(fn (Show $show): array => [
                'id' => $show->id,
                'name' => $show->name,
                'show_type' => $show->show_type->value,
                'brand' => $show->brand?->value,
                'outcomes' => [],
            ])
            ->values();

        return Inertia::render('gm/results', [
            'week_number' => $week,
            'shows' => $shows,
            'new_emails' => Email::where('save_id', $save->id)->where('brand', $save->player_brand)->where('week_number', $week)->count(),
        ]);
    }
}
