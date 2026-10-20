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

    // Firebase Cloud Messaging HTTP v1: Pfad zur Dienstkonto-JSON aus der Firebase-Konsole.
    // Ohne Datei bleibt Push einfach aus.
    // Optional: KI-Aufteilung großer Aufgaben in Schritte (Konzept §11.6). Ohne Schlüssel aus.
    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
    ],

    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/private/firebase.json')),
        'channel' => env('FCM_ANDROID_CHANNEL', 'heldenhaus'),
    ],

];
