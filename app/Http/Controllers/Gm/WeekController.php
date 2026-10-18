<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Services\Week\WeekResolver;
use Illuminate\Http\RedirectResponse;

/**
 * Airs the current week's shows (§2 week resolution).
 */
class WeekController extends Controller
{
    public function advance(Save $save, WeekResolver $resolver): RedirectResponse
    {
        $resolution = $resolver->resolve($save);

        return to_route('gm.results', [$save, $resolution->week_number]);
    }
}
