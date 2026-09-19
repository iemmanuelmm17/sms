<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PushVapidCommand extends Command
{
    protected $signature = 'push:vapid-keys';
    protected $description = 'Generate VAPID keys for Web Push (prints .env lines).';

    public function handle(): int
    {
        if (!class_exists(\Minishlink\WebPush\VAPID::class)) {
            $this->error('minishlink/web-push is not installed. Run: composer require minishlink/web-push');
            return 1;
        }
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        $this->line('Add these to .env (and restart queue workers):');
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:ops@your-domain.com');
        return 0;
    }
}
