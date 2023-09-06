<?php

namespace QuetzalStudio\RequestLogger;

use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RequestLogger
{
    protected $request = null;

    protected $response = null;

    protected ?string $channel = null;

    public function __construct()
    {
        $this->channel = config('request_logger.log.channel');

        if (! config("logging.channels.{$this->channel}")) {
            config(['logging.channels.request' => [
                'driver' => 'daily',
                'path' => storage_path('logs/request/request.log'),
                'level' => env('LOG_LEVEL', 'debug'),
                'days' => 14,
                'replace_placeholders' => true,
            ]]);
        }
    }

    public function channel(string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function request($request): self
    {
        if (! $request instanceof Request && ! $request instanceof Psr7Request) {
            throw new InvalidArgumentException(get_class($request));
        }

        $this->request = $request;

        return $this;
    }

    public function response($response): self
    {
        if (! $response instanceof Response && ! $response instanceof SymfonyResponse) {
            throw new InvalidArgumentException(get_class($response));
        }

        $this->response = $response;

        return $this;
    }

    public function create(string $message = null)
    {
        Log::channel($this->channel)->debug($this->message($message), $this->context());
    }

    protected function message(string $message = null)
    {
        if (is_null($message)) {
            return implode(' ', [
                $this->requestMethod(),
                $this->requestUrl(),
                $this->response->getStatusCode(),
            ]);
        }
    }

    protected function requestMethod(): string
    {
        return $this->request instanceof Psr7Request ? $this->request->getMethod() : $this->request->method();
    }

    protected function requestUrl(): string
    {
        return $this->request instanceof Psr7Request
            ? $this->request->getUri()
            : $this->request->url();
    }

    protected function requestQuery(): array
    {
        if ($this->request instanceof Psr7Request) {
            $query = [];

            parse_str($this->request->getUri()->getQuery(), $query);

            return $query;
        }

        return $this->request->query();
    }

    protected function requestBody(): array
    {
        if ($this->request instanceof Psr7Request) {
            $body = json_decode($this->request->getBody(), true);

            $this->request->getBody()->rewind();

            return $body ?? [];
        }

        return $this->request->request->all();
    }

    protected function requestHeaders(): array
    {
        if ($this->request instanceof Psr7Request) {
            $headers = $this->request->getHeaders();

            foreach ($headers as $key => $value) {
                unset($headers[$key]);

                $headers[strtolower($key)] = $value;
            }

            return $headers;
        }

        return $this->request->header();
    }

    protected function responseBody(): array
    {
        if ($this->response instanceof SymfonyResponse) {
            $content = json_decode($this->response->getContent(), true);

            return is_array($content) ? $content : [];
        }

        $body = json_decode($this->response->getBody(), true);

        $this->response->getBody()->rewind();

        return $body ?? [];
    }

    public function responseHeaders(): array
    {
        if ($this->response instanceof SymfonyResponse) {
            return $this->response->headers->all();
        }

        return $this->response->headers();
    }

    protected function context(): array
    {
        return [
            'request' => $this->requestContext(),
            'response' => $this->responseContext(),
        ];
    }

    protected function requestContext(): array
    {
        $data = [
            'method' => $this->requestMethod(),
            'url' => $this->requestUrl(),
            'query' => $this->requestQuery(),
            'body' => $this->requestBody(),
            'headers' => $this->requestHeaders(),
        ];

        $this->masking(config('request_logger.masking.request.body', []), $data, 'body');
        $this->masking(config('request_logger.masking.request.headers', []), $data, 'headers');

        return $data;
    }

    protected function responseContext(): array
    {
        $data = [
            'status' => $this->response->getStatusCode(),
            'body' => $this->responseBody(),
            'headers' => $this->responseHeaders(),
        ];

        $this->masking(config('request_logger.masking.response.body', []), $data, 'body');
        $this->masking(config('request_logger.masking.response.headers', []), $data, 'headers');

        return $data;
    }

    protected function masking(array $keys, array &$data, string $field): void
    {
        foreach ($keys as $key) {
            if (! isset($data[$field][$key])) {
                continue;
            }

            $key = "{$field}.{$key}";

            data_set($data, $key, '********');
        }
    }
}
