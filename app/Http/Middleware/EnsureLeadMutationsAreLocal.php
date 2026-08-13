<?php

namespace App\Http\Middleware;

use App\Support\LocalEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLeadMutationsAreLocal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            abort_unless(LocalEnvironment::allowsMutations(), 404);
        }

        return $next($request);
    }
}
