<?php

declare(strict_types=1);

return [

    'enabled' => env('SEQ_ENABLED', true),

    'url' => env('SEQ_URL', 'http://localhost:5341'),

    'api_key' => env('SEQ_API_KEY'),

    'level' => env('SEQ_LEVEL', env('LOG_LEVEL', 'debug')),

    'timeout' => env('SEQ_TIMEOUT', 2),

    'connect_timeout' => env('SEQ_CONNECT_TIMEOUT', 1),

    'batch_size' => env('SEQ_BATCH_SIZE', 100),

    'flush_interval' => env('SEQ_FLUSH_INTERVAL', 5),

    'max_event_size' => env('SEQ_MAX_EVENT_SIZE', 262144),

    'properties' => [
        'Application' => env('APP_NAME', 'Laravel'),
        'Environment' => env('APP_ENV', 'production'),
    ],

];
