<?php
return [
    'wot_app_id' => getenv('WOT_APP_ID') ?: '', // Заменить на реальный WOT API ключ
    'wot_openid_app_id' => getenv('WOT_OPENID_APP_ID') ?: '', // Заменить на реальный OpenID ключ
    'wot_server' => 'api.worldoftanks.eu',
    'wot_openid_url' => 'https://api.worldoftanks.eu/wot/auth/login/',
    'redirect_uri' => 'https://supports.kz/wg/auth.php',
    'expected_values_path' => __DIR__ . '/expected_values.json',
    'cache_ttl' => 10800, // 3 часа
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'dbname' => getenv('DB_NAME') ?: 'wot_stats',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];