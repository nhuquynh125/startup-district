<?php
declare(strict_types=1);

use App\Config\Env;

// Single place where configuration is assembled from the environment.
return [
    'app' => [
        'name'  => Env::get('APP_NAME', 'Startup Street'),
        'env'   => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
    ],
    'db' => [
        'host'    => Env::get('DB_HOST', '127.0.0.1'),
        'port'    => (int) Env::get('DB_PORT', '3306'),
        'name'    => Env::get('DB_NAME', 'startup_street'),
        'user'    => Env::get('DB_USER', 'root'),
        'pass'    => Env::get('DB_PASS', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    ],
];
