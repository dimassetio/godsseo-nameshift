<?php

return [

    'zcom' => [
        'enabled' => env('ZCOM_AUTOMATION_ENABLED', false),
        'login_url' => env('ZCOM_LOGIN_URL', 'https://my.web.z.com/index.php?rp=/login'),
        'domains_url' => env('ZCOM_DOMAINS_URL', 'https://my.web.z.com/clientarea.php?action=domains'),
        'node_binary' => env('ZCOM_NODE_BINARY', 'node'),
        'headless' => env('ZCOM_HEADLESS', true),
        'timeout' => (int) env('ZCOM_PROCESS_TIMEOUT', 240),
        'sync_timeout' => (int) env('ZCOM_SYNC_PROCESS_TIMEOUT', 1800),
        'navigation_timeout_ms' => (int) env('ZCOM_NAVIGATION_TIMEOUT_MS', 45000),
        'browser_executable_path' => env('ZCOM_BROWSER_EXECUTABLE_PATH'),
        'browsers_path' => env('PLAYWRIGHT_BROWSERS_PATH'),
        'diagnostics_path' => env('ZCOM_DIAGNOSTICS_PATH') ?: storage_path('app/private/zcom-diagnostics'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
