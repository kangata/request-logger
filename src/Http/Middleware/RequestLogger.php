<?php

namespace QuetzalStudio\RequestLogger\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use QuetzalStudio\RequestLogger\RequestLogger as Logger;
use Symfony\Component\HttpFoundation\Response;

class RequestLogger
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        (new Logger)->request($request)->response($response)->create();

        return $response;
    }
}
