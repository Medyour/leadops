<?php

namespace App\Domain\LeadProcessing;

use App\Domain\LeadProcessing\Data\RoutingResult;

final class LeadRouter
{
    /** @param array<string, string>|null $routes */
    public function __construct(private ?array $routes = null)
    {
        $this->routes ??= config('leadops.routing');
    }

    public function route(string $serviceInterest): RoutingResult
    {
        return new RoutingResult($this->routes[$serviceInterest] ?? null);
    }
}
