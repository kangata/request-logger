<?php

namespace QuetzalStudio\RequestLogger\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use QuetzalStudio\RequestLogger\RequestLogger;

class RecursiveMaskingTest extends TestCase
{
    protected function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data), $status, ['Content-Type' => 'application/json']);
    }

    protected function log(array $body, string $url = 'http://localhost/api/users'): array
    {
        $request = Request::create($url, 'POST');

        (new RequestLogger)->request($request)->response($this->jsonResponse($body))->create();

        $records = $this->logRecords();

        return end($records)['context']['response']['body'];
    }

    public function test_recursive_key_masks_at_the_root()
    {
        config()->set('request_logger.masking.response.body', ['**.password']);

        $this->assertSame('********', $this->log(['password' => 'secret'])['password']);
    }

    public function test_recursive_key_masks_when_nested()
    {
        config()->set('request_logger.masking.response.body', ['**.password']);

        $body = $this->log(['data' => ['user' => ['password' => 'secret']]]);

        $this->assertSame('********', $body['data']['user']['password']);
    }

    public function test_recursive_key_masks_inside_a_list()
    {
        config()->set('request_logger.masking.response.body', ['**.password']);

        $body = $this->log(['data' => [['password' => 'a'], ['password' => 'b']]]);

        $this->assertSame('********', $body['data'][0]['password']);
        $this->assertSame('********', $body['data'][1]['password']);
    }

    public function test_recursive_key_leaves_other_keys_alone()
    {
        config()->set('request_logger.masking.response.body', ['**.password']);

        $body = $this->log(['password' => 'secret', 'name' => 'Jonathan']);

        $this->assertSame('Jonathan', $body['name']);
    }

    public function test_recursive_key_replaces_a_whole_object()
    {
        config()->set('request_logger.masking.response.body', ['**.credentials']);

        $body = $this->log(['credentials' => ['client_id' => 'a', 'client_secret' => 'b']]);

        $this->assertSame('********', $body['credentials']);
    }

    public function test_recursive_key_accepts_a_mask_format()
    {
        config()->set('request_logger.masking.response.body', [
            '**.phone' => ['mask' => ['show_last' => 4]],
        ]);

        $body = $this->log(['data' => [['phone' => '081234567890']]]);

        $this->assertSame('********7890', $body['data'][0]['phone']);
    }

    public function test_recursive_key_accepts_a_glob()
    {
        config()->set('request_logger.masking.response.body', ['**.*_token']);

        $body = $this->log([
            'access_token' => 'abc',
            'data' => [['refresh_token' => 'def']],
        ]);

        $this->assertSame('********', $body['access_token']);
        $this->assertSame('********', $body['data'][0]['refresh_token']);
    }

    public function test_an_exact_recursive_key_beats_a_glob()
    {
        config()->set('request_logger.masking.response.body', [
            '**.card_holder' => ['mask' => ['show_first' => 2]],
            '**.card*' => ['mask' => ['show_last' => 4]],
        ]);

        $body = $this->log(['card_holder' => 'Jonathan', 'card_number' => '4111111111111111']);

        $this->assertSame('Jo******', $body['card_holder']);
        $this->assertSame('************1111', $body['card_number']);
    }

    public function test_recursive_key_honours_only()
    {
        config()->set('request_logger.masking.response.body', [
            '**.password' => ['only' => ['oauth/token']],
        ]);

        $body = $this->log(['data' => [['password' => 'secret']]], 'http://localhost/api/users');

        $this->assertSame('secret', $body['data'][0]['password']);

        $body = $this->log(['data' => [['password' => 'secret']]], 'http://localhost/oauth/token');

        $this->assertSame('********', $body['data'][0]['password']);
    }

    public function test_wildcard_path_segment_masks_every_element()
    {
        config()->set('request_logger.masking.response.body', ['data.*.token']);

        $body = $this->log(['data' => [['token' => 'a'], ['token' => 'b']]]);

        $this->assertSame('********', $body['data'][0]['token']);
        $this->assertSame('********', $body['data'][1]['token']);
    }

    public function test_literal_and_recursive_keys_coexist()
    {
        config()->set('request_logger.masking.response.body', [
            'data.user.email' => ['mask' => 'email'],
            '**.password',
        ]);

        $body = $this->log(['data' => [
            'user' => ['email' => 'john.doe@example.com', 'password' => 'secret'],
            'devices' => [['password' => 'secret']],
        ]]);

        $this->assertSame('jo******@example.com', $body['data']['user']['email']);
        $this->assertSame('********', $body['data']['user']['password']);
        $this->assertSame('********', $body['data']['devices'][0]['password']);
    }

    public function test_recursive_key_masks_a_header()
    {
        config()->set('request_logger.masking.request.headers', ['**.authorization']);

        $request = Request::create('http://localhost/api/users', 'POST');
        $request->headers->set('authorization', 'Bearer abc.def');

        (new RequestLogger)->request($request)->response($this->jsonResponse(['ok' => true]))->create();

        $headers = $this->logRecords()[0]['context']['request']['headers'];

        $this->assertSame(['********'], $headers['authorization']);
    }

    public function test_an_unmatched_recursive_key_changes_nothing()
    {
        config()->set('request_logger.masking.response.body', ['**.no_such_key']);

        $this->assertSame(['ok' => true], $this->log(['ok' => true]));
    }
}
