<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DevEduHub API — Phase 1 + Phase 2 + Priority 2 hardening
|--------------------------------------------------------------------------
| Rate limits:
|   submissions.store  → 10 per minute per user
|   submissions.retry  → 3 per hour per user
|   auth.login         → 5 per minute per IP
|   auth.register      → 5 per minute per IP
*/

// ── Public auth (IP-based throttle to block brute force) ─────────────────────
Route::middleware('throttle:5,1')->prefix('auth')->group(function () {
    Route::post('register',      [AuthController::class, 'register']);
    Route::post('login',         [AuthController::class, 'login']);
    Route::get('verify/{token}', [AuthController::class, 'verifyEmail']);
});

// ── Authenticated routes ──────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── Auth ──────────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);
    });

    // ── Courses ───────────────────────────────────────────────────────────
    Route::apiResource('courses', CourseController::class);

    // ── Assignments ───────────────────────────────────────────────────────
    Route::get('courses/{course}/assignments', [AssignmentController::class, 'index']);
    Route::apiResource('assignments', AssignmentController::class)->except(['index']);

    // Publish / unpublish toggle (teacher only — enforced by AssignmentPolicy)
    Route::patch('assignments/{assignment}/publish', [AssignmentController::class, 'togglePublish']);

    // ── Submissions (rate-limited) ─────────────────────────────────────────
    // Store: max 10 submissions per minute per authenticated user
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('submissions', [SubmissionController::class, 'store']);
    });

    // Retry: max 3 retries per 60 minutes per authenticated user
    Route::middleware('throttle:3,60')->group(function () {
        Route::post('submissions/{submission}/retry', [SubmissionController::class, 'retry']);
    });

    // Remaining submission routes (no throttle needed — read-only or teacher)
    Route::get('submissions',                              [SubmissionController::class, 'index']);
    Route::get('submissions/{submission}',                 [SubmissionController::class, 'show']);
    Route::patch('submissions/{submission}',               [SubmissionController::class, 'update']);
    Route::get('assignments/{assignment}/submissions',     [SubmissionController::class, 'byAssignment']);

    // ── Enrollments ───────────────────────────────────────────────────────
    Route::get('enrollments',                  [EnrollmentController::class, 'index']);
    Route::post('enrollments',                 [EnrollmentController::class, 'store']);
    Route::delete('enrollments/{enrollment}',  [EnrollmentController::class, 'destroy']);

    // ── Notifications ─────────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get('/',           [NotificationController::class, 'index']);
        Route::get('unread',      [NotificationController::class, 'unread']);
        Route::patch('read-all',  [NotificationController::class, 'markAllRead']);
        Route::patch('{id}/read', [NotificationController::class, 'markRead']);
    });
});