<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GraderApiService
{
    private string $baseUrl;
    private int    $timeout;
    private int    $connectTimeout;

    public function __construct()
    {
        $this->baseUrl        = config('grader.url', 'http://localhost:8000');
        $this->timeout        = config('grader.timeout', 120);
        $this->connectTimeout = config('grader.connect_timeout', 10);
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Send a submission to the Python grader and return the result.
     *
     * @return array{
     *   status: string,
     *   score: float,
     *   passed_tests: int,
     *   total_tests: int,
     *   logs: string,
     *   feedback: string,
     *   execution_time: float,
     * }
     *
     * @throws RuntimeException on API failure or unexpected response
     */
    public function grade(string $submissionId, string $repoUrl, ?string $commitSha = null): array
    {
        $payload = array_filter([
            'repo'          => $repoUrl,
            'commit_sha'    => $commitSha,
            'submission_id' => $submissionId,
        ]);

        Log::info("[GraderApi] Sending to grader", [
            'submission_id' => $submissionId,
            'repo'          => $repoUrl,
            'commit_sha'    => $commitSha,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(
                    times: 2,
                    sleepMilliseconds: 2000,
                    when: fn($e) => $e instanceof ConnectionException,
                )
                ->withHeaders(['Accept' => 'application/json'])
                ->post("{$this->baseUrl}/grade", $payload);

            if ($response->failed()) {
                $detail = $response->json('detail') ?? $response->body();
                Log::error("[GraderApi] HTTP error", [
                    'submission_id' => $submissionId,
                    'status'        => $response->status(),
                    'detail'        => substr($detail, 0, 500),
                ]);
                throw new RuntimeException(
                    "Grader returned HTTP {$response->status()}: {$detail}"
                );
            }

            $data = $response->json();

            // Validate response shape
            $this->validateResponse($data, $submissionId);

            Log::info("[GraderApi] Response received", [
                'submission_id'  => $submissionId,
                'score'          => $data['score'],
                'status'         => $data['status'],
                'execution_time' => $data['execution_time'] ?? null,
            ]);

            return $data;

        } catch (ConnectionException $e) {
            Log::error("[GraderApi] Connection failed", [
                'submission_id' => $submissionId,
                'error'         => $e->getMessage(),
            ]);
            throw new RuntimeException("Cannot reach grader service: {$e->getMessage()}", 0, $e);

        } catch (RequestException $e) {
            Log::error("[GraderApi] Request exception", [
                'submission_id' => $submissionId,
                'error'         => $e->getMessage(),
            ]);
            throw new RuntimeException("Grader request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Ping the grader health endpoint. Returns true if service is up.
     */
    public function isHealthy(): bool
    {
        try {
            return Http::timeout(5)
                ->get("{$this->baseUrl}/health")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function validateResponse(?array $data, string $submissionId): void
    {
        if (! $data) {
            throw new RuntimeException("Empty response from grader");
        }

        $required = ['status', 'score', 'feedback'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                throw new RuntimeException(
                    "Grader response missing required field '{$key}' for submission {$submissionId}"
                );
            }
        }

        if (! is_numeric($data['score']) || $data['score'] < 0 || $data['score'] > 100) {
            throw new RuntimeException(
                "Grader returned invalid score: {$data['score']}"
            );
        }
    }
}
