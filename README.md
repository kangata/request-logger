### Install package
```composer require quetzal-studio/request-logger```


### Publish config file
```php artisan vendor:publish --tag=request-logger-config```


### Using middleware
#### Laravel 9 (and older)
```
protected $routeMiddleware = [
    // ...
    'request.logger' => \QuetzalStudio\RequestLogger\Http\Middleware\RequestLogger::class,
];
```

#### Laravel 10
````
protected $middlewareAliases = [
    // ...
    'request.logger' => \QuetzalStudio\RequestLogger\Http\Middleware\RequestLogger::class,
];
````
