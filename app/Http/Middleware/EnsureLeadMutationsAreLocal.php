<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLeadMutationsAreLocal
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('leads.create', 'leads.store')) {
            abort_unless(app()->environment(['local', 'testing']), 404);
        }

        return $next($request);
    }
}
