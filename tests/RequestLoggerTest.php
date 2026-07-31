<?php

namespace QuetzalStudio\RequestLogger\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use QuetzalStudio\RequestLogger\RequestLogger;

class RequestLoggerTest extends TestCase
{
    protected function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data), $status, ['Content-Type' => 'application/json']);
    }

    public function test_it_logs_a_custom_message_without_request_and_response()
    {
        (new RequestLogger)->create('Payment callback received');

        $records = $this->logRecords();

        $this->assertCount(1, $records);
        $this->assertSame('Payment callback received', $records[0]['message']);
        $this->assertSame([], $records[0]['context']);
    }

    public function test_it_throws_when_no_message_and_no_request_or_response()
    {
        $this->expectException(InvalidArgumentException::class);

        (new RequestLogger)->create();
    }

    public function test_it_logs_to_a_custom_channel()
    {
        (new RequestLogger)->channel('custom')->create('hello');

        $this->assertCount(0, $this->logRecords());
        $this->assertCount(1, $this->logRecords('custom'));
    }

    public function test_it_builds_the_default_message_from_request_and_response()
    {
        $request = Request::create('http://localhost/api/payment?foo=bar', 'POST');

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $records = $this->logRecords();

        $this->assertSame('POST http://localhost/api/payment 200', $records[0]['message']);
        $this->assertArrayHasKey('request', $records[0]['context']);
        $this->assertArrayHasKey('response', $records[0]['context']);
    }

    public function test_without_context_keeps_the_default_message()
    {
        $request = Request::create('http://localhost/api/payment', 'POST');

        (new RequestLogger)
            ->request($request)
            ->response($this->jsonResponse(['ok' => true]))
            ->withoutContext()
            ->create();

        $records = $this->logRecords();

        $this->assertSame('POST http://localhost/api/payment 200', $records[0]['message']);
        $this->assertSame([], $records[0]['context']);
    }

    public function test_context_can_be_disabled_by_config()
    {
        config()->set('request_logger.log.context', false);

        $request = Request::create('http://localhost/api/payment', 'POST');

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $this->assertSame([], $this->logRecords()[0]['context']);
    }

    public function test_it_fully_masks_plain_keys()
    {
        $request = Request::create('http://localhost/login', 'POST', [
            'email' => 'john.doe@example.com',
            'password' => 'secret123',
        ]);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $body = $this->logRecords()[0]['context']['request']['body'];

        $this->assertSame('********', $body['password']);
        $this->assertSame('john.doe@example.com', $body['email']);
    }

    public function test_it_masks_request_headers()
    {
        $request = Request::create('http://localhost/login', 'POST', ['name' => 'test']);
        $request->headers->set('Authorization', 'Bearer secret-token');

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $headers = $this->logRecords()[0]['context']['request']['headers'];

        $this->assertSame(['********'], $headers['authorization']);
    }

    public function test_it_masks_response_body()
    {
        $request = Request::create('http://localhost/oauth/token', 'POST', ['grant_type' => 'password']);

        (new RequestLogger)
            ->request($request)
            ->response($this->jsonResponse(['access_token' => 'abcdef', 'expires_in' => 3600]))
            ->create();

        $body = $this->logRecords()[0]['context']['response']['body'];

        $this->assertSame('********', $body['access_token']);
        $this->assertSame(3600, $body['expires_in']);
    }

    public function test_partial_masking_show_last()
    {
        config()->set('request_logger.masking.request.body', [
            'phone' => ['mask' => ['show_last' => 4]],
        ]);

        $request = Request::create('http://localhost/register', 'POST', ['phone' => '081234567890']);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $this->assertSame('********7890', $this->logRecords()[0]['context']['request']['body']['phone']);
    }

    public function test_partial_masking_show_first()
    {
        config()->set('request_logger.masking.request.body', [
            'name' => ['mask' => ['show_first' => 2]],
        ]);

        $request = Request::create('http://localhost/register', 'POST', ['name' => 'Jonathan']);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $this->assertSame('Jo******', $this->logRecords()[0]['context']['request']['body']['name']);
    }

    public function test_partial_masking_is_capped_at_four_characters()
    {
        config()->set('request_logger.masking.request.body', [
            'phone' => ['mask' => ['show_last' => 10]],
        ]);

        $request = Request::create('http://localhost/register', 'POST', ['phone' => '081234567890']);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $this->assertSame('********7890', $this->logRecords()[0]['context']['request']['body']['phone']);
    }

    public function test_partial_masking_falls_back_to_full_mask_for_short_values()
    {
        config()->set('request_logger.masking.request.body', [
            'phone' => ['mask' => ['show_last' => 4]],
        ]);

        $request = Request::create('http://localhost/register', 'POST', ['phone' => '1234567']);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $this->assertSame('********', $this->logRecords()[0]['context']['request']['body']['phone']);
    }

    public function test_email_masking()
    {
        config()->set('request_logger.masking.request.body', [
            'email' => ['mask' => 'email'],
        ]);

        $request = Request::create('http://localhost/register', 'POST', ['email' => 'john.doe@example.com']);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $this->assertSame('jo******@example.com', $this->logRecords()[0]['context']['request']['body']['email']);
    }

    public function test_only_rule_masks_matching_url()
    {
        config()->set('request_logger.masking.request.body', [
            'client_secret' => ['only' => ['oauth/token']],
        ]);

        $request = Request::create('http://localhost/oauth/token', 'POST', ['client_secret' => 'abc123']);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $this->assertSame('********', $this->logRecords()[0]['context']['request']['body']['client_secret']);
    }

    public function test_only_rule_skips_other_urls()
    {
        config()->set('request_logger.masking.request.body', [
            'client_secret' => ['only' => ['oauth/token']],
        ]);

        $request = Request::create('http://localhost/api/other', 'POST', ['client_secret' => 'abc123']);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $this->assertSame('abc123', $this->logRecords()[0]['context']['request']['body']['client_secret']);
    }

    public function test_nested_keys_can_be_masked_with_dot_notation()
    {
        config()->set('request_logger.masking.request.body', ['user.password']);

        $request = Request::create('http://localhost/register', 'POST', [
            'user' => ['name' => 'Jonathan', 'password' => 'secret123'],
        ]);

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $body = $this->logRecords()[0]['context']['request']['body'];

        $this->assertSame('********', $body['user']['password']);
        $this->assertSame('Jonathan', $body['user']['name']);
    }
}
