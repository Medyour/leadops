<?php

namespace App\Http\Middleware;

use App\Support\LocalEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLocalEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(LocalEnvironment::allowsMutations(), 404);

        return $next($request);
    }
}
