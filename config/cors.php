<?php

$frontendOrigins = array_values(array_filter(array_map(
    static function ($origin) {
        $origin = trim($origin);

        if ($origin === '') {
            return '';
        }

        return rtrim($origin, '/');
    },
    explode(',', (string) env('FRONTEND_URL', ''))
)));

$environment = env('APP_ENV', 'production');
$isProduction = in_array($environment, ['production', 'staging'], true);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $frontendOrigins ?: ($isProduction ? [] : ['*']),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'X-KOBI-KEY',
        'Accept',
        'Origin',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
