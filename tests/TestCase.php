<?php

namespace QuetzalStudio\RequestLogger\Tests;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Orchestra\Testbench\TestCase as Orchestra;
use QuetzalStudio\RequestLogger\RequestLoggerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            RequestLoggerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        foreach (['request', 'custom'] as $channel) {
            $app['config']->set("logging.channels.{$channel}", [
                'driver' => 'monolog',
                'handler' => TestHandler::class,
            ]);
        }
    }

    /**
     * Log records as arrays, across Monolog 2 (arrays) and Monolog 3 (LogRecord objects).
     */
    protected function logRecords(string $channel = 'request'): array
    {
        $records = Log::channel($channel)->getLogger()->getHandlers()[0]->getRecords();

        return array_map(function ($record) {
            return is_array($record) ? $record : $record->toArray();
        }, $records);
    }
}
