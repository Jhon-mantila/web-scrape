<?php

use App\VideoProcessing\Http\Middleware\AuthenticateVideoWorker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware([AuthenticateVideoWorker::class, SubstituteBindings::class])
                ->prefix('worker/v1')
                ->group(base_path('routes/worker.php'));
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('app:scheduler-tick')->everyMinute();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
