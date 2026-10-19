<?php

use App\Http\ApiErrorRenderer;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ResolveDemoUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'demo.user' => ResolveDemoUser::class,
            'role' => RequireRole::class,
        ]);

        // Know who is asking before looking up the pool, so an unknown caller cannot probe which pools exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveDemoUser::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (Throwable $e, Request $request) => ApiErrorRenderer::render($e, $request));
    })->create();
