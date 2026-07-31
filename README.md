# Request Logger

Request logger for Laravel. Logs incoming requests (and outgoing Guzzle/HTTP client requests) with their responses to a dedicated log channel, with configurable masking for sensitive fields.

## Requirements

| | Version |
|---|---|
| PHP | 7.4 – 8.5 |
| Laravel | 6.x – 13.x |

## Install package

```
composer require quetzal-studio/request-logger
```

## Publish config file

```
php artisan vendor:publish --tag=request-logger-config
```

## Using middleware

### Laravel 6 – 9

```php
// app/Http/Kernel.php
protected $routeMiddleware = [
    // ...
    'request.logger' => \QuetzalStudio\RequestLogger\Http\Middleware\RequestLogger::class,
];
```

### Laravel 10

```php
// app/Http/Kernel.php
protected $middlewareAliases = [
    // ...
    'request.logger' => \QuetzalStudio\RequestLogger\Http\Middleware\RequestLogger::class,
];
```

### Laravel 11 – 13

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'request.logger' => \QuetzalStudio\RequestLogger\Http\Middleware\RequestLogger::class,
    ]);
})
```

Then attach it to routes:

```php
Route::post('/payment', PaymentController::class)->middleware('request.logger');

// Or with a custom log channel
Route::post('/payment', PaymentController::class)->middleware('request.logger:payment');
```

Logging runs in the middleware's `terminate()` method, after the response has been sent to the client, so it does not add to response time.

## Log channel

By default logs are written to the `request` channel. If that channel is not defined in `config/logging.php`, a daily driver writing to `storage/logs/request/request.log` (14 days retention) is created automatically. Change the default channel via `request_logger.log.channel` or per route with the middleware parameter.

## Message-only logging

By default every entry includes the full request/response context. To log only the message line (`{http_method} {url} {http_status}`) without the request and response payloads, set the config globally:

```php
'log' => [
    'channel' => 'request',
    'context' => false,
],
```

Or per call with `withoutContext()`:

```php
(new RequestLogger)
    ->request($request)
    ->response($response)
    ->withoutContext()
    ->create(); // logs "POST https://example.com/api/payment 200" with no context
```

## Masking

Keys listed in the `masking` config are replaced before logging. A plain key is fully masked with `********`:

```php
'masking' => [
    'request' => [
        'body' => [
            'password',
            'password_confirmation',
            'client_secret',
        ],
        'headers' => [
            'authorization',
        ],
    ],
    'response' => [
        'body' => [
            'access_token',
            'refresh_token',
        ],
    ],
],
```

Keys support dot notation for nested values (e.g. `user.password`).

### Partial masking

For PII fields you need to trace in the logs (which email/phone/account a request was about), use the `mask` option to keep part of the value visible:

```php
'body' => [
    'password',                                          // ********
    'email' => ['mask' => 'email'],                      // jo****@example.com
    'phone' => ['mask' => ['show_last' => 4]],           // ********7890
    'account_number' => ['mask' => ['show_last' => 4]],  // ******3456
    'name' => ['mask' => ['show_first' => 2]],           // Jo******
],
```

- `show_last` / `show_first` are capped at 4 characters, even if you configure more.
- Values too short to mask meaningfully (fewer than 4 hidden characters left) fall back to the full `********` mask.
- Keep secrets (`password`, `client_secret`, `authorization`, tokens) fully masked — partial masking is meant for identifiers, not credentials.

### Mask only on specific URLs

```php
'body' => [
    'client_secret' => ['only' => ['oauth/token']],
    'phone' => ['only' => ['api/v1/users'], 'mask' => ['show_last' => 4]],
],
```

The key is masked only when the request URL matches one of the patterns.

## Log a custom message

The logger can also be used directly, without request/response context:

```php
use QuetzalStudio\RequestLogger\RequestLogger;

(new RequestLogger)->create('Payment callback received');

(new RequestLogger)->channel('audit')->create('Manual sync triggered');
```

You can attach only a request, only a response, or both — whatever is set is included in the log context (with masking applied):

```php
(new RequestLogger)
    ->request($request)   // Illuminate\Http\Request or GuzzleHttp\Psr7\Request
    ->response($response) // Illuminate\Http\Client\Response or Symfony Response
    ->create();           // message defaults to "METHOD url STATUS"
```
