<?php

namespace Toshkq93\BehaviorDiffLaravel\Runner;

use Closure;

/**
 * Stands in for the CSRF middleware during the run: generated requests
 * carry no token, and the check is not what is being compared.
 */
final class PassThroughMiddleware
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}
