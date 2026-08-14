<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureLeadMutationsAreLocal;
use App\Support\LocalEnvironment;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::get('/health', static fn () => response()->noContent());

            if (LocalEnvironment::allowsMutations()) {
                Route::middleware('web')
                    ->group(base_path('routes/local-web.php'));

                Route::middleware('api')
                    ->prefix('api')
                    ->group(base_path('routes/local-api.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');

        if (is_string($trustedProxies) && trim($trustedProxies) !== '') {
            $trustedProxies = trim($trustedProxies);

            $middleware->trustProxies(
                at: str_contains($trustedProxies, ',')
                    ? array_map('trim', explode(',', $trustedProxies))
                    : $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $middleware->append(AddSecurityHeaders::class);
        $middleware->web(prepend: [EnsureLeadMutationsAreLocal::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(
            static fn (Response $response) => AddSecurityHeaders::apply($response),
        );
    })->create();
