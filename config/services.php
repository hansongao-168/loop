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

    'ai' => [
        'api_key' => env('AI_GATEWAY_API_KEY'),
        'base_url' => rtrim(env('LLM_BASE_URL', 'http://ollama:11434/v1'), '/'),
        'api_key_upstream' => env('LLM_API_KEY', 'ollama'),
        'chat_model' => env('LLM_CHAT_MODEL', 'qwen3:8b'),
        'embedding_model' => env('LLM_EMBEDDING_MODEL', 'nomic-embed-text'),
        'timeout' => (int) env('LLM_TIMEOUT', 120),
        'chunk_size' => (int) env('RAG_CHUNK_SIZE', 1200),
        'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
        'top_k' => (int) env('RAG_TOP_K', 5),
    ],

    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
