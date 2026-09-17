<?php

use App\Http\Middleware\EnsurePremiumAccess;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\Exceptions\TenantResolutionConflictException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
        $middleware->appendToGroup('web', [SetLocale::class, SecurityHeaders::class, ResolveTenantContext::class]);
        $middleware->prependToGroup('api', [SecurityHeaders::class]);
        // ADR-001 §5: the tenant must be resolved before route-model binding, so
        // bound models resolve under the correct tenant (not the caller's default).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantContext::class);
        $middleware->alias([
            'ability' => CheckAbilities::class,
            'premium' => EnsurePremiumAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ADR-001 §5: a cross-tenant request is a forbidden (4xx) outcome, never
        // a 500. Refusing to choose between conflicting tenant signals, and
        // touching tenant-owned data without a resolved context, are both
        // authorisation failures from the caller's point of view.
        $exceptions->render(function (TenantResolutionConflictException|MissingTenantContextException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 403)
                : response($e->getMessage(), 403);
        });
    })->create();
