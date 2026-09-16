<?php

use App\Http\Middleware\EnsurePremiumAccess;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Liberu\Foundation\Localization\Http\Middleware\SetLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Traefik (TLS terminated at the edge): trust the proxy headers
        // so X-Forwarded-Proto=https is honoured. Without this the app builds
        // http:// asset/route URLs and the browser blocks Livewire JS as
        // mixed content, so every form (including login) silently fails.
        $middleware->trustProxies(at: '*');
        $middleware->appendToGroup('web', [SetLocale::class, SecurityHeaders::class]);
        $middleware->prependToGroup('api', [SecurityHeaders::class]);
        $middleware->alias([
            'ability' => CheckAbilities::class,
            'premium' => EnsurePremiumAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
