<?php

return [
    'log' => [
        'channel' => 'request',
    ],

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
            'headers' => [

            ],
        ],
    ],
];
