<?php

namespace QuetzalStudio\RequestLogger\Tests;

use Illuminate\Support\Facades\Route;
use QuetzalStudio\RequestLogger\Http\Middleware\RequestLogger;

class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/login', function () {
            return response()->json(['access_token' => 'abcdef', 'ok' => true]);
        })->middleware(RequestLogger::class);

        Route::post('/payment', function () {
            return response()->json(['ok' => true]);
        })->middleware(RequestLogger::class.':custom');
    }

    public function test_it_logs_request_and_response_after_the_request()
    {
        $this->post('/login', ['email' => 'john.doe@example.com', 'password' => 'secret123'])
            ->assertOk();

        $records = $this->logRecords();

        $this->assertCount(1, $records);
        $this->assertSame('POST http://localhost/login 200', $records[0]['message']);

        $context = $records[0]['context'];

        $this->assertSame('********', $context['request']['body']['password']);
        $this->assertSame('john.doe@example.com', $context['request']['body']['email']);
        $this->assertSame('********', $context['response']['body']['access_token']);
        $this->assertSame(200, $context['response']['status']);
    }

    public function test_channel_middleware_parameter_survives_until_terminate()
    {
        $this->post('/payment', ['amount' => 100])->assertOk();

        $this->assertCount(0, $this->logRecords());
        $this->assertCount(1, $this->logRecords('custom'));
        $this->assertSame('POST http://localhost/payment 200', $this->logRecords('custom')[0]['message']);
    }

    public function test_response_is_returned_untouched()
    {
        $this->post('/login', ['password' => 'secret123'])
            ->assertOk()
            ->assertJson(['access_token' => 'abcdef', 'ok' => true]);
    }
}
