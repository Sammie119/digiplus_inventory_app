<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\SetCurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', SetCurrentTenant::class);
        $middleware->alias([
            'super-admin' => EnsureSuperAdmin::class,
            'tenant.active' => EnsureTenantActive::class,
        ]);
        $middleware->validateCsrfTokens(except: ['webhooks/paystack']);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('subscriptions:expire')->dailyAt('00:05');
        $schedule->command('reports:send-scheduled')->dailyAt('06:00');
        $schedule->command('subscriptions:send-renewal-reminders')->dailyAt('07:00');
        $schedule->command('backups:run-scheduled')->weeklyOn(1, '02:00');
        $schedule->command('database:backup')->dailyAt('03:30');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
