<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection
    |--------------------------------------------------------------------------
    | Set QUEUE_CONNECTION=redis in .env for production.
    | Use 'sync' locally to run jobs immediately without a worker.
    */
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver'       => 'database',
            'connection'   => env('DB_QUEUE_CONNECTION'),
            'table'        => env('DB_QUEUE_TABLE', 'jobs'),
            'queue'        => env('DB_QUEUE', 'default'),
            'retry_after'  => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        // ── Redis (primary for production) ─────────────────────────────────
        'redis' => [
            'driver'      => 'redis',
            'connection'  => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue'       => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 200),
            'block_for'   => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Named Queues — processed by separate worker commands
    |--------------------------------------------------------------------------
    |
    | grading:       php artisan queue:work redis --queue=grading --timeout=180
    | notifications: php artisan queue:work redis --queue=notifications
    | default:       php artisan queue:work redis --queue=default
    |
    */
    'queues' => [
        'grading'       => ['timeout' => 180, 'tries' => 3],
        'notifications' => ['timeout' => 30,  'tries' => 5],
        'default'       => ['timeout' => 60,  'tries' => 3],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Jobs
    |--------------------------------------------------------------------------
    */
    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table'    => 'failed_jobs',
    ],

];