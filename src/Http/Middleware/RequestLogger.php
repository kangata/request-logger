<?php

namespace QuetzalStudio\RequestLogger\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use QuetzalStudio\RequestLogger\RequestLogger as Logger;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RequestLogger
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $channel = null): Response
    {
        $response = $next($request);

        try {
            $logger = new Logger;

            if ($channel) {
                $logger->channel($channel);
            }

            $logger->request($request)->response($response)->create();
        } catch (Throwable $e) {
            Log::error($e);
        }

        return $response;
    }
}
