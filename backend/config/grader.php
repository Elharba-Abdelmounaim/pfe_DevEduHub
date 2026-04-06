<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Python Grader Microservice
    |--------------------------------------------------------------------------
    |
    | Configuration for the DevEduHub auto-grading Python service.
    | Set GRADER_URL in .env to point at your deployed grader.
    |
    */

    // Base URL of the Python FastAPI grader service
    'url' => env('GRADER_URL', 'http://localhost:8000'),

    // Total request timeout (seconds) — should exceed grader's own timeout
    'timeout' => (int) env('GRADER_TIMEOUT', 150),

    // Connection-only timeout (seconds)
    'connect_timeout' => (int) env('GRADER_CONNECT_TIMEOUT', 10),

    // Queue to dispatch GradeSubmissionJob on
    'queue' => env('GRADER_QUEUE', 'grading'),

];
