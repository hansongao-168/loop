<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional location to locate the various service credentials.
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

    /*
    |--------------------------------------------------------------------------
    | LOOP Central AI Dispatcher
    |--------------------------------------------------------------------------
    |
    | The LoopRouter is the single entry point for all AI calls in the
    | project. Services inject it instead of a concrete HTTP client. It
    | resolves a provider/model per task, applies rate limits, opens a
    | circuit breaker on repeated failures, and records usage to the
    | ai_call_logs table.
    |
    */

    'loop' => [
        'default_provider' => env('LOOP_DEFAULT_PROVIDER', 'openai_compatible'),
        // Routing strategy for multi-candidate chains:
        //   failover    - try candidates in order, switch on failure
        //   round_robin - rotate the starting candidate per request (load
        //                 spread), still failing over within one request
        //   single      - only ever use the first candidate
        'default_strategy' => env('LOOP_DEFAULT_STRATEGY', 'failover'),
        'cache_ttl' => (int) env('LOOP_CACHE_TTL', 3600),
        'stream' => filter_var(env('LOOP_STREAM', false), FILTER_VALIDATE_BOOL),

        'providers' => [
            'openai_compatible' => [
                'driver' => 'openai_compatible',
                'base_url' => rtrim((string) env('LLM_BASE_URL', 'http://ollama:11434/v1'), '/'),
                'api_key' => env('LLM_API_KEY', 'ollama'),
                'timeout' => (int) env('LLM_TIMEOUT', 120),
                'retry' => [
                    'times' => (int) env('LOOP_RETRY_TIMES', 2),
                    'sleep_ms' => (int) env('LOOP_RETRY_SLEEP_MS', 300),
                ],
            ],
            // Optional second provider for automatic model switching.
            // Referenced from candidate chains as `backup@<model>`.
            // Only registered when a base URL is configured.
            'backup' => [
                'driver' => 'openai_compatible',
                'base_url' => rtrim((string) env('LLM_BACKUP_BASE_URL', ''), '/'),
                'api_key' => env('LLM_BACKUP_API_KEY', 'ollama'),
                'timeout' => (int) env('LLM_BACKUP_TIMEOUT', 120),
                'retry' => [
                    'times' => (int) env('LOOP_RETRY_TIMES', 2),
                    'sleep_ms' => (int) env('LOOP_RETRY_SLEEP_MS', 300),
                ],
            ],
        ],

        // Candidate chains per task. Each value is either:
        //  - a comma separated string of `provider@model` entries (the
        //    LOOP_<TASK>_CANDIDATES env makes this configurable without
        //    touching PHP), or
        //  - an array of ['provider' => ..., 'model' => ...] entries.
        // The first candidate that succeeds wins; later entries are the
        // automatic fallback models.
        'models' => [
            'embed' => env('LOOP_EMBED_CANDIDATES', env('LOOP_EMBED_PROVIDER', 'openai_compatible').'@'.env('LLM_EMBEDDING_MODEL', 'nomic-embed-text')),
            'chat' => env('LOOP_CHAT_CANDIDATES', env('LOOP_CHAT_PROVIDER', 'openai_compatible').'@'.env('LLM_CHAT_MODEL', 'qwen3:8b')),
            'extract' => env('LOOP_EXTRACT_CANDIDATES', env('LOOP_EXTRACT_PROVIDER', 'openai_compatible').'@'.env('GRAPH_RAG_EXTRACTION_MODEL', env('LLM_CHAT_MODEL', 'qwen3:8b'))),
            'summary' => env('LOOP_SUMMARY_CANDIDATES', env('LOOP_SUMMARY_PROVIDER', 'openai_compatible').'@'.env('GRAPH_RAG_SUMMARY_MODEL', env('LLM_CHAT_MODEL', 'qwen3:8b'))),
            'rerank' => env('LOOP_RERANK_CANDIDATES', env('LOOP_RERANK_PROVIDER', 'openai_compatible').'@'.env('GRAPH_RAG_RERANK_MODEL', env('LLM_CHAT_MODEL', 'qwen3:8b'))),
            'answer' => env('LOOP_ANSWER_CANDIDATES', env('LOOP_ANSWER_PROVIDER', 'openai_compatible').'@'.env('LLM_CHAT_MODEL', 'qwen3:8b')),
            'chat_direct' => env('LOOP_CHAT_DIRECT_CANDIDATES', env('LOOP_CHAT_DIRECT_PROVIDER', 'openai_compatible').'@'.env('LLM_CHAT_MODEL', 'qwen3:8b')),
        ],

        'limits' => [
            'default' => [
                'rpm' => (int) env('LOOP_RPM', 0),
                'tpm' => (int) env('LOOP_TPM', 0),
                'concurrency' => (int) env('LOOP_CONCURRENCY', 0),
            ],
            // Optional per (provider:model) overrides, keyed
            // `provider:model`. Overrides `default` for that pair.
            'per_pair' => [
                // 'openai_compatible:qwen3:8b' => ['rpm' => 60, 'tpm' => 100000, 'concurrency' => 4],
            ],
        ],

        // Health probes (loop:health command / scheduler). A provider
        // whose last probe failed within the TTL is deprioritized to the
        // back of its candidate chain until a probe or real call
        // succeeds again.
        'health' => [
            'ttl_seconds' => (int) env('LOOP_HEALTH_TTL', 300),
        ],

        'circuit' => [
            'failure_threshold' => (int) env('LOOP_CB_FAILURE_THRESHOLD', 5),
            'cooldown_seconds' => (int) env('LOOP_CB_COOLDOWN', 30),
        ],

        'recording' => [
            'enabled' => filter_var(env('LOOP_RECORD', true), FILTER_VALIDATE_BOOL),
            'sample_rate' => (float) env('LOOP_RECORD_SAMPLE', 1.0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy AI & GraphRAG configuration
    |--------------------------------------------------------------------------
    |
    | These blocks remain so existing .env files and downstream code
    | that still reads config('services.ai.*') / services.graph_rag.*
    | keep working. The services.ai.* values (gateway key, models,
    | chunking and top_k defaults) are read by the middleware, the query
    | service and the chunker; model routing itself lives in the loop
    | block above.
    |
    */

    'ai' => [
        'api_key' => env('AI_GATEWAY_API_KEY'),
        'base_url' => rtrim((string) env('LLM_BASE_URL', 'http://ollama:11434/v1'), '/'),
        'api_key_upstream' => env('LLM_API_KEY', 'ollama'),
        'chat_model' => env('LLM_CHAT_MODEL', 'qwen3:8b'),
        'embedding_model' => env('LLM_EMBEDDING_MODEL', 'nomic-embed-text'),
        'timeout' => (int) env('LLM_TIMEOUT', 120),
        'chunk_size' => (int) env('RAG_CHUNK_SIZE', 1200),
        'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
        'top_k' => (int) env('RAG_TOP_K', 5),
    ],

    'graph_rag' => [
        'enabled' => filter_var(env('GRAPH_RAG_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Louvain hierarchy depth for community detection: 1 stores only
        // the finest partition (legacy behaviour), higher values add
        // condensed summary levels on top.
        'community_levels' => (int) env('GRAPH_RAG_COMMUNITY_LEVELS', 2),
        'extraction_model' => env('GRAPH_RAG_EXTRACTION_MODEL', env('LLM_CHAT_MODEL', 'qwen3:8b')),
        'summary_model' => env('GRAPH_RAG_SUMMARY_MODEL', env('LLM_CHAT_MODEL', 'qwen3:8b')),
        'max_nodes' => (int) env('GRAPH_RAG_MAX_NODES', 50),
        'rerank_enabled' => filter_var(env('GRAPH_RAG_RERANK_ENABLED', false), FILTER_VALIDATE_BOOL),
        'rerank_model' => env('GRAPH_RAG_RERANK_MODEL', env('LLM_CHAT_MODEL', 'qwen3:8b')),
        'rerank_candidates' => (int) env('GRAPH_RAG_RERANK_CANDIDATES', 20),
        'semantic_entity_resolution' => filter_var(env('GRAPH_RAG_SEMANTIC_ENTITY_RESOLUTION', false), FILTER_VALIDATE_BOOL),
        'entity_similarity_threshold' => (float) env('GRAPH_RAG_ENTITY_SIMILARITY_THRESHOLD', 0.92),
        'entity_similarity_margin' => (float) env('GRAPH_RAG_ENTITY_SIMILARITY_MARGIN', 0.05),
    ],

    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
