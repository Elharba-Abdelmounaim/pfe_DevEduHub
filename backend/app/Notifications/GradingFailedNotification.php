<?php

namespace App\Notifications;

use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GradingFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $queue = 'notifications';

    public function __construct(
        private readonly Submission $submission,
        private readonly string     $reason = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $assignment = $this->submission->assignment;

        return (new MailMessage)
            ->subject('⚠ Grading Failed — Action Required')
            ->greeting("Hello {$notifiable->first_name},")
            ->line("We were unable to grade your submission for **{$assignment->title}**.")
            ->line("This may happen if your repository is private, the code doesn't run, or our grader encountered an error.")
            ->line("Please check your submission and try again, or contact your instructor.")
            ->action('View Submission', url("/submissions/{$this->submission->id}"))
            ->line('We apologise for the inconvenience.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'          => 'grading_failed',
            'submission_id' => $this->submission->id,
            'assignment_id' => $this->submission->assignment_id,
            'message'       => 'Grading failed. Please check your submission.',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
