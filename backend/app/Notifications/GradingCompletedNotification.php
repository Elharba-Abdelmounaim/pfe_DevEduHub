<?php

namespace App\Notifications;

use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GradingCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $queue = 'notifications';

    public function __construct(
        private readonly Submission $submission,
        private readonly array      $result,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    // ── Email ─────────────────────────────────────────────────────────────
    public function toMail(object $notifiable): MailMessage
    {
        $score      = number_format($this->result['score'], 1);
        $assignment = $this->submission->assignment;
        $passed     = $this->result['status'] === 'success';

        return (new MailMessage)
            ->subject($passed ? "✓ Assignment Graded — {$score}/100" : "Assignment Graded — {$score}/100")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your submission for **{$assignment->title}** has been graded.")
            ->line("**Score: {$score} / 100**")
            ->when($this->result['feedback'] ?? false, function (MailMessage $msg) {
                return $msg->line("**Feedback:**")->line($this->result['feedback']);
            })
            ->action('View Submission', url("/submissions/{$this->submission->id}"))
            ->line('Thank you for submitting your work!');
    }

    // ── Database ──────────────────────────────────────────────────────────
    public function toDatabase(object $notifiable): array
    {
        return [
            'type'          => 'grading_completed',
            'submission_id' => $this->submission->id,
            'assignment_id' => $this->submission->assignment_id,
            'score'         => $this->result['score'],
            'status'        => $this->result['status'],
            'message'       => "Your assignment has been graded. Score: {$this->result['score']}/100",
        ];
    }

    // ── Array (API consumers) ─────────────────────────────────────────────
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
