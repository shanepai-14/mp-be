<?php

return [
    'pool' => [
        'max_connections' => env('GPS_POOL_MAX_CONNECTIONS', 20),
        'connection_timeout' => env('GPS_POOL_CONNECTION_TIMEOUT', 300),
        'idle_timeout' => env('GPS_POOL_IDLE_TIMEOUT', 60),
        'connect_timeout' => env('GPS_POOL_CONNECT_TIMEOUT', 3),
        'socket_timeout' => env('GPS_POOL_SOCKET_TIMEOUT', 2),
    ]
];