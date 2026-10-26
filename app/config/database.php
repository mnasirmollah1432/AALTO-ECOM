<?php

use App\Core\Env;

return [
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => (int) Env::get('DB_PORT', 3306),
    'database' => Env::get('DB_DATABASE', 'aalto_mart'),
    'username' => Env::get('DB_USERNAME', 'root'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),
];
