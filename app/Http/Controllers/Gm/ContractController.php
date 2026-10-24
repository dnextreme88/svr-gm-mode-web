<?php

namespace App\Http\Controllers\Gm;

use App\Enums\ContractLength;
use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Contracts\ContractService;
use App\Services\Week\Steps\TrackDeficit;
use App\Services\Week\WeekResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Renegotiate, release and sign (§2, §3 Free Agency).
 */
class ContractController extends Controller
{
    public function __construct(private ContractService $contracts) {}

    public function renegotiate(Request $request, Save $save, SaveWrestler $saveWrestler): RedirectResponse
    {
        $length = $this->length($request);
        $this->assertOwn($save, $saveWrestler);

        DB::transaction(fn () => $this->contracts->renegotiate($saveWrestler, $length, $save->current_week));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$saveWrestler->name()} re-signed for {$length->label()}."]);

        return back();
    }

    public function release(Save $save, SaveWrestler $saveWrestler): RedirectResponse
    {
        $this->assertOwn($save, $saveWrestler);
        app(WeekResolver::class)->assertNotAiring($save);

        DB::transaction(function () use ($save, $saveWrestler): void {
            $this->contracts->release($saveWrestler, $save->current_week);
            $brand = $save->brand($save->player_brand);

            if ($brand->deficit_weeks > 0 && $brand->deficit_choice === null) {
                $brand->update(['deficit_choice' => TrackDeficit::CHOICE_RELEASE]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$saveWrestler->name()} released to Free Agency."]);

        return to_route('gm.rosters.index', $save);
    }

    public function sign(Request $request, Save $save, SaveWrestler $saveWrestler): RedirectResponse
    {
        $length = $this->length($request);
        app(WeekResolver::class)->assertNotAiring($save);

        DB::transaction(fn () => $this->contracts->signFreeAgent($save, $saveWrestler, $save->player_brand, $length, $save->current_week));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$saveWrestler->name()} signed. They join the roster next week."]);

        return back();
    }

    private function length(Request $request): ContractLength
    {
        $data = $request->validate(['length_weeks' => ['required', Rule::enum(ContractLength::class)]]);

        return ContractLength::from((int) $data['length_weeks']);
    }

    private function assertOwn(Save $save, SaveWrestler $wrestler): void
    {
        if ($wrestler->brand !== $save->player_brand) {
            throw new GameRuleException("{$wrestler->name()} isn't on your roster.");
        }
    }
}
