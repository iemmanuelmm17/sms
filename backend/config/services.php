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
        'dynalink' => [
                'core_base'     => env('DYNALINK_CORE_BASE', 'https://core2-nyc.dynalink.net/ns-api/v2'),
                'auth_base'     => env('DYNALINK_AUTH_BASE', 'https://nms1.nyc.birns.net/ns-api/v2'),
                'client_id'     => env('DYNALINK_CLIENT_ID', 'report'),
                'client_secret' => env('DYNALINK_CLIENT_SECRET'),
                'service_user'  => env('DYNALINK_SERVICE_USER'),
                'service_pass'  => env('DYNALINK_SERVICE_PASS'),
                'webhook_url'   => env('DYNALINK_WEBHOOK_URL'),
        'revio' => [
                // Local testing escape hatch: REVIO_VERIFY_SSL=false skips TLS
                // verification for Rev.io. Never use in production.
                'verify' => filter_var(env('REVIO_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        ],
        ],

    'mail' => [
        'smtp_host' => env('MAIL_SMTP_HOST'),
        'smtp_port' => env('MAIL_SMTP_PORT'),
        'smtp_encryption' => env('MAIL_SMTP_ENCRYPTION'),
        'smtp_username' => env('MAIL_SMTP_USERNAME'),
        'smtp_password' => env('MAIL_SMTP_PASSWORD'),
        'from_address' => env('MAIL_FROM_ADDRESS'),
        'from_name' => env('MAIL_FROM_NAME'),
        'imap_host' => env('MAIL_IMAP_HOST'),
        'imap_port' => env('MAIL_IMAP_PORT'),
        'imap_encryption' => env('MAIL_IMAP_ENCRYPTION'),
        'imap_username' => env('MAIL_IMAP_USERNAME'),
        'imap_password' => env('MAIL_IMAP_PASSWORD'),
        'inbound_domain' => env('MAIL_INBOUND_DOMAIN'),
        'cap_sender_daily' => env('MAIL_CAP_SENDER_DAILY'),
        'cap_dest_hourly' => env('MAIL_CAP_DEST_HOURLY'),
    ],

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@localhost'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
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
];
