<?php

namespace App\Console\Commands;

use App\Services\EmailSmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PollEmailSmsCommand extends Command
{
    protected $signature = 'mail:poll-email-sms';
    protected $description = 'Poll the email gateway inbox for email-to-SMS and SMS replies.';

    public function handle(EmailSmsService $svc): int
    {
        try {
            $stats = $svc->pollInbox();
        } catch (\Throwable $e) {
            Cache::put('emailsms:last_poll', ['at' => now()->toISOString(), 'ok' => false,
                'error' => mb_substr($e->getMessage(), 0, 200)], now()->addDays(7));
            Log::warning('EmailSms poll failed: ' . $e->getMessage());
            $this->error('Poll failed: ' . $e->getMessage());
            return self::FAILURE;
        }
        if (empty($stats['ready'])) {
            Cache::put('emailsms:last_poll', ['at' => now()->toISOString(), 'ok' => true,
                'ready' => false], now()->addDays(7));
            $this->info('Email inbox not configured — skipping.');
            return self::OK;
        }
        Cache::put('emailsms:last_poll', ['at' => now()->toISOString(), 'ok' => true, 'ready' => true,
            'folders' => count($stats['folders'] ?? []), 'fetched' => $stats['fetched'] ?? 0,
            'sent' => $stats['sent'] ?? 0, 'replies' => $stats['replies'] ?? 0,
            'skipped' => $stats['skipped'] ?? 0, 'purged' => $stats['purged'] ?? 0,
            'errors' => $stats['errors'] ?? 0], now()->addDays(7));
        $this->info('EmailSms poll: folders=' . count($stats['folders'] ?? [])
            . " fetched={$stats['fetched']} sent={$stats['sent']}"
            . " replies={$stats['replies']} skipped={$stats['skipped']} purged={$stats['purged']} errors={$stats['errors']}");
        return self::OK;
    }
}
