<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // UI audit (2026-11-12): pages that aren't found or fail show a GM
        // Mode error page with a way back, not Laravel's bare white page.
        // Server errors keep the debug page while developing.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            $status = $response->getStatusCode();
            $shown = in_array($status, [403, 404], true)
                || (in_array($status, [500, 503], true) && ! app()->hasDebugModeEnabled());

            if (! $shown || $request->expectsJson()) {
                return $response;
            }

            return Inertia::render('gm/error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
