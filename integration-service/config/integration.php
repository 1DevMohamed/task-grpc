<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integration Architecture Configuration
    |--------------------------------------------------------------------------
    |
    | Central configuration for the async integration layer:
    | SQS queues, retry policies, RPC timeouts, and processing parameters.
    |
    */

    // ── SQS Queues ─────────────────────────────────────────

    'sqs' => [
        'queue'     => env('SQS_QUEUE', 'integration-inbound'),
        'dlq'       => env('SQS_DLQ', 'integration-dlq'),
        'wait_time' => (int) env('SQS_WAIT_TIME_SECONDS', 20),
    ],

    // ── Retry Policy ───────────────────────────────────────

    'retry' => [
        // Application-level retry delays in milliseconds (per attempt)
        // SQS maxReceiveCount is the outer retry boundary
        'backoff_schedule_ms' => [0, 2000, 5000, 15000, 30000],

        // Max application-level attempts per SQS delivery
        'max_attempts' => (int) env('INTEGRATION_MAX_ATTEMPTS', 5),

        // Add random jitter (±25%) to prevent thundering herd
        'use_jitter' => true,
    ],

    // ── RPC Timeouts ───────────────────────────────────────

    'rpc' => [
        'vendor' => [
            'host'       => env('VENDOR_GRPC_HOST', 'vendor-svc:50054'),
            'timeout_ms' => (int) env('VENDOR_RPC_TIMEOUT_MS', 5000),
        ],
        'inventory' => [
            'host'       => env('INVENTORY_GRPC_HOST', 'inventory-svc:9001'),
            'timeout_ms' => (int) env('INVENTORY_RPC_TIMEOUT_MS', 5000),
        ],
    ],

    // ── Inbox / Processing ─────────────────────────────────

    'inbox' => [
        // If a PROCESSING event is older than this, consider it stale
        // (the original worker likely crashed)
        'stale_threshold_seconds' => (int) env('INBOX_STALE_THRESHOLD', 120),
    ],

    // ── Visibility Timeout Analysis ────────────────────────
    //
    // DB inbox check:     ~50ms
    // Vendor gRPC (max):  5,000ms
    // Inventory gRPC:     5,000ms
    // DB status update:   ~50ms
    // Total max:          ~10,100ms
    // Safety margin (6x): 60,000ms = 60 seconds
    //
    // ElasticMQ defaultVisibilityTimeout must be >= this value.
    // See sqs/elasticmq.conf
    //

];
