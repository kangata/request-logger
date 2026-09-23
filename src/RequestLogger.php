<?php

namespace QuetzalStudio\RequestLogger;

use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RequestLogger
{
    protected $request = null;

    protected $response = null;

    protected ?string $channel = null;

    protected bool $withoutContext = false;

    public function __construct()
    {
        $this->channel = config('request_logger.log.channel');

        $this->withoutContext = ! config('request_logger.log.context', true);

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

    public function withoutContext(): self
    {
        $this->withoutContext = true;

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

    public function create(?string $message = null)
    {
        Log::channel($this->channel)->info($this->message($message), $this->context());
    }

    protected function message(?string $message = null)
    {
        if (! is_null($message)) {
            return $message;
        }

        if (! $this->request || ! $this->response) {
            throw new InvalidArgumentException('Message is required when request or response is not set.');
        }

        return implode(' ', [
            $this->requestMethod(),
            preg_replace('/\?.*$/', '', $this->requestUrl()),
            $this->response->getStatusCode(),
        ]);
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

            if (empty($body)) {
                parse_str($this->request->getBody(), $body);
            }

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
        if ($this->withoutContext) {
            return [];
        }

        $context = [];

        if ($this->request) {
            $context['request'] = $this->requestContext();
        }

        if ($this->response) {
            $context['response'] = $this->responseContext();
        }

        return $context;
    }

    protected function requestContext(): array
    {
        $data = [
            'method' => $this->requestMethod(),
            'url' => preg_replace('/\?.*$/', '', $this->requestUrl()),
            'query' => $this->requestQuery(),
            'body' => $this->requestBody(),
            'headers' => $this->requestHeaders(),
        ];

        $this->masking(config('request_logger.masking.request.query', []), $data, 'query');
        $this->masking(config('request_logger.masking.request.body', []), $data, 'body');
        $this->masking(config('request_logger.masking.request.headers', []), $data, 'headers');

        if (empty($data['query'])) {
            unset($data['query']);
        }

        if (empty($data['body'])) {
            unset($data['body']);
        }

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

    /**
     * Rule keys are 'password', 'user.password', 'data.*.password' or
     * '**.password' (any depth), the last of which may glob: '**.*_token'.
     * Exact '**' names win over globs, globs match in config order.
     */
    protected function masking(array $keys, array &$data, string $field): void
    {
        $exact = [];
        $globs = [];

        foreach ($keys as $key => $rules) {
            $key = (string) (is_array($rules) ? $key : $rules);
            $rules = is_array($rules) ? $rules : [];

            if (strncmp($key, '**.', 3) !== 0) {
                $this->maskPath($data, $field, $key, $rules);

                continue;
            }

            if (Arr::has($rules, 'only') && ! $this->maskUrlMatches(data_get($rules, 'only', []))) {
                continue;
            }

            $name = substr($key, 3);
            $format = [data_get($rules, 'mask')];

            if (strpbrk($name, '*?') === false) {
                $exact[$name] = $format;
            } else {
                $globs[] = ['/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($name, '/')).'$/', $format];
            }
        }

        if (($exact || $globs) && is_array(Arr::get($data, $field))) {
            $cache = [];

            $this->maskTree($data[$field], $exact, $globs, $field == 'headers', $cache);
        }
    }

    protected function maskPath(array &$data, string $field, string $key, array $rules): void
    {
        if (Arr::has($rules, 'only') && ! $this->maskUrlMatches(data_get($rules, 'only', []))) {
            return;
        }

        if (strpos($key, '*') === false) {
            if (Arr::has($data, "{$field}.{$key}")) {
                $this->maskValue($data, $field, $key, $rules);
            }

            return;
        }

        foreach ($this->expandPath($key, Arr::get($data, $field)) as $path) {
            $this->maskValue($data, $field, $path, $rules);
        }
    }

    protected function expandPath(string $pattern, $payload): array
    {
        if (! is_array($payload) || $pattern === '') {
            return [];
        }

        $paths = [''];

        foreach (explode('.', $pattern) as $segment) {
            $next = [];

            foreach ($paths as $prefix) {
                $node = $prefix === '' ? $payload : Arr::get($payload, $prefix);

                if (! is_array($node)) {
                    continue;
                }

                foreach ($segment === '*' ? array_keys($node) : [$segment] as $k) {
                    if (array_key_exists($k, $node)) {
                        $next[] = $prefix === '' ? (string) $k : $prefix.'.'.$k;
                    }
                }
            }

            $paths = $next;
        }

        return $paths;
    }

    /**
     * A matched key is replaced whole and not descended into.
     */
    protected function maskTree(array &$node, array $exact, array $globs, bool $isHeaders, array &$cache): void
    {
        foreach ($node as $k => &$value) {
            // A list index is never a key name.
            if (is_int($k)) {
                if (is_array($value)) {
                    $this->maskTree($value, $exact, $globs, $isHeaders, $cache);
                }

                continue;
            }

            $rule = isset($cache[$k]) ? $cache[$k] : ($cache[$k] = $this->resolveMaskRule($k, $exact, $globs));

            if ($rule === false) {
                if (is_array($value)) {
                    $this->maskTree($value, $exact, $globs, $isHeaders, $cache);
                }

                continue;
            }

            $masked = $this->mask($isHeaders && is_array($value) ? reset($value) : $value, $rule[0]);

            $value = $isHeaders ? [$masked] : $masked;
        }

        unset($value);
    }

    /**
     * @return array|false
     */
    protected function resolveMaskRule(string $name, array $exact, array $globs)
    {
        if (array_key_exists($name, $exact)) {
            return $exact[$name];
        }

        foreach ($globs as $glob) {
            if (preg_match($glob[0], $name)) {
                return $glob[1];
            }
        }

        return false;
    }

    protected function maskUrlMatches(array $urls): bool
    {
        foreach ($urls as $url) {
            if (preg_match('/'.preg_quote($url, '/').'/', $this->requestUrl())) {
                return true;
            }
        }

        return false;
    }

    protected function maskValue(array &$data, string $field, string $key, array $rules): void
    {
        $value = Arr::get($data, "{$field}.{$key}");

        if ($field == 'headers' && is_array($value)) {
            $value = reset($value);
        }

        $masked = $this->mask($value, data_get($rules, 'mask'));

        Arr::set($data, "{$field}.{$key}", $field == 'headers' ? [$masked] : $masked);
    }

    protected function mask($value, $format = null): string
    {
        if (is_null($format) || (! is_string($value) && ! is_numeric($value))) {
            return '********';
        }

        $value = (string) $value;

        if ($format === 'email') {
            return $this->maskEmail($value);
        }

        $show = min(4, (int) (data_get($format, 'show_last') ?? data_get($format, 'show_first') ?? 0));

        if ($show < 1 || strlen($value) - $show < 4) {
            return '********';
        }

        $stars = str_repeat('*', strlen($value) - $show);

        return Arr::has((array) $format, 'show_last')
            ? $stars.substr($value, -$show)
            : substr($value, 0, $show).$stars;
    }

    protected function maskEmail(string $value): string
    {
        $pos = strpos($value, '@');

        if ($pos === false) {
            return '********';
        }

        $local = substr($value, 0, $pos);
        $domain = substr($value, $pos);

        $show = strlen($local) >= 5 ? 2 : 0;

        return substr($local, 0, $show).str_repeat('*', max(strlen($local) - $show, 4)).$domain;
    }
}
