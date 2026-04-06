<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Notifications\GradingCompletedNotification;
use App\Notifications\GradingFailedNotification;
use App\Services\GraderApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GradeSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ── Queue config ──────────────────────────────────────────────────────
    public string $queue    = 'grading';
    public int    $timeout  = 180;   // max seconds this job runs (covers HTTP + processing)
    public int    $tries    = 3;     // Laravel auto-retry on failure
    public int    $backoff  = 60;    // seconds between retries (exponential: 60, 120, 180)

    // ── Constructor ───────────────────────────────────────────────────────
    public function __construct(
        public readonly Submission $submission
    ) {}

    // ── Handle ────────────────────────────────────────────────────────────
    public function handle(GraderApiService $grader): void
    {
        $subId = $this->submission->id;

        Log::info("[GradeJob] Starting", [
            'submission_id' => $subId,
            'attempt'       => $this->attempts(),
            'repo'          => $this->submission->github_repo_url,
        ]);

        // ── 1. Mark as queued → grading ───────────────────────────────────
        $this->submission->update(['submission_status' => 'grading']);

        // ── 2. Call Python grader API ─────────────────────────────────────
        $result = $grader->grade(
            submissionId: $subId,
            repoUrl:      $this->submission->github_repo_url,
            commitSha:    $this->submission->github_commit_sha,
        );

        // ── 3. Persist grading result ─────────────────────────────────────
        $autoScore  = $result['score'];
        $finalScore = $autoScore;   // teacher can override in Phase 3

        $this->submission->update([
            'submission_status' => 'graded',
            'auto_grade_score'  => $autoScore,
            'final_score'       => $finalScore,
            'teacher_feedback'  => $result['feedback'] ?? null,
            'retry_count'       => $this->attempts() - 1,
            'last_retry_at'     => $this->attempts() > 1 ? now() : null,
        ]);

        Log::info("[GradeJob] Graded successfully", [
            'submission_id' => $subId,
            'score'         => $autoScore,
            'passed_tests'  => $result['passed_tests'] ?? null,
            'total_tests'   => $result['total_tests']  ?? null,
        ]);

        // ── 4. Notify student ─────────────────────────────────────────────
        $student = $this->submission->student;
        $student->notify(new GradingCompletedNotification($this->submission, $result));
    }

    // ── Retry backoff: 60s → 120s → 180s ─────────────────────────────────
    public function backoff(): array
    {
        return [60, 120, 180];
    }

    // ── Called when all retries exhausted ─────────────────────────────────
    public function failed(Throwable $exception): void
    {
        Log::error("[GradeJob] Permanently failed", [
            'submission_id' => $this->submission->id,
            'attempts'      => $this->attempts(),
            'error'         => $exception->getMessage(),
        ]);

        // Mark submission as failed so student + teacher know
        $this->submission->update([
            'submission_status' => 'failed',
            'retry_count'       => $this->attempts(),
            'last_retry_at'     => now(),
        ]);

        // Notify student that grading failed
        $this->submission->student->notify(
            new GradingFailedNotification($this->submission, $exception->getMessage())
        );
    }
}
