<?php

namespace App\Jobs;

use App\Services\EmailSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the notification email for an inbound MMS a few seconds after its
 * first event, so the twin event (caption or media) has time to arrive.
 * A no-op when the email has already gone out. Never throws.
 */
class SendMmsNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $key) {}

    public function handle(EmailSmsService $email): void
    {
        $email->sendMms($this->key);
    }
}
