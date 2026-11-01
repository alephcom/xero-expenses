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

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    'xero' => [
        'client_id' => env('XERO_CLIENT_ID'),
        'client_secret' => env('XERO_CLIENT_SECRET'),
        'redirect' => env('XERO_REDIRECT_URI', env('APP_URL').'/xero/callback'),
        'allow_open_signup' => filter_var(env('XERO_ALLOW_OPEN_SIGNUP', false), FILTER_VALIDATE_BOOLEAN),
        'sso_auto_reviewer_access' => filter_var(env('XERO_SSO_AUTO_REVIEWER_ACCESS', true), FILTER_VALIDATE_BOOLEAN),
        'authorize_url' => 'https://login.xero.com/identity/connect/authorize',
        'token_url' => 'https://identity.xero.com/connect/token',
        'connections_url' => 'https://api.xero.com/connections',
        'api_base' => 'https://api.xero.com/api.xro/2.0',
        'scopes' => explode(' ', env('XERO_SCOPES', 'offline_access accounting.transactions accounting.contacts accounting.settings')),
        'sso' => [
            'scopes' => explode(' ', env('XERO_SSO_SCOPES', 'openid profile email')),
            'issuer' => env('XERO_SSO_ISSUER', 'https://identity.xero.com'),
            'jwks_uri' => env('XERO_SSO_JWKS_URI', 'https://identity.xero.com/.well-known/openid-configuration/jwks'),
        ],
    ],

];
