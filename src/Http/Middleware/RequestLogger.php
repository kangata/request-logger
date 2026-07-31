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
    protected ?string $channel = null;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $channel = null): Response
    {
        $this->channel = $channel;

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $logger = new Logger;

            if ($this->channel) {
                $logger->channel($this->channel);
            }

            $logger->request($request)->response($response)->create();
        } catch (Throwable $e) {
            Log::error($e);
        }
    }
}
