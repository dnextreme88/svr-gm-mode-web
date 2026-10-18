<?php

namespace App\Http\Middleware;

use App\Http\Presenters\GamePresenter;
use App\Models\Save;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the GM broadcast header (`game`) with every page under a save.
 */
class ShareGameState
{
    public function __construct(private GamePresenter $presenter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $save = $request->route('save');

        if ($save instanceof Save) {
            Inertia::share('game', fn (): array => $this->presenter->shell($save->refresh()));
        }

        return $next($request);
    }
}
