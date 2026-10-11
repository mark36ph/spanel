<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'SPanel',
        'environment' => 'development',
        'base_url' => 'http://127.0.0.1:8080',
        'session_name' => 'spanel_session',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'spanel',
        'username' => 'spanel_app',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
];
