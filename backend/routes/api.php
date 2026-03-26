<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DevEduHub API Routes — Phase 1 MVP
|--------------------------------------------------------------------------
|
| Auth:          /api/auth/*
| Courses:       /api/courses/*
| Assignments:   /api/assignments/* and /api/courses/{course}/assignments
| Submissions:   /api/submissions/* and /api/assignments/{assignment}/submissions
| Enrollments:   /api/enrollments/*
|
*/

// ── Public auth routes ────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',    [AuthController::class, 'login']);
    Route::get('verify/{token}', [AuthController::class, 'verifyEmail']);
});

// ── Protected routes ──────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── Auth ─────────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);
    });

    // ── Courses ───────────────────────────────────────────────────────────
    Route::apiResource('courses', CourseController::class);

    // ── Assignments (nested under course + standalone) ────────────────────
    Route::get('courses/{course}/assignments', [AssignmentController::class, 'index']);
    Route::apiResource('assignments', AssignmentController::class)
         ->except(['index']);                   // index is via courses/{course}/assignments

    // ── Submissions ────────────────────────────────────────────────────────
    // Student: POST + GET own; Teacher: GET all for their assignments
    Route::apiResource('submissions', SubmissionController::class)
         ->except(['destroy']);                 // submissions are never deleted

    // Teacher: view all submissions for a specific assignment
    Route::get('assignments/{assignment}/submissions',
        [SubmissionController::class, 'byAssignment']);

    // ── Enrollments ────────────────────────────────────────────────────────
    Route::get('enrollments',              [EnrollmentController::class, 'index']);
    Route::post('enrollments',             [EnrollmentController::class, 'store']);
    Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy']);

});