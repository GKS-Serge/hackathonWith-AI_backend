<?php

return [

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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
    ],

    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
        'base_url' => env('YOUTUBE_API_BASE_URL', 'https://www.googleapis.com/youtube/v3'),
        'timeout' => (int) env('YOUTUBE_TIMEOUT', 15),
        'cache_ttl_search' => (int) env('YOUTUBE_CACHE_TTL_SEARCH', 86400), // 24 heures en secondes
        'cache_ttl_details' => (int) env('YOUTUBE_CACHE_TTL_DETAILS', 604800), // 7 jours en secondes
    ],

];
