<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * php artisan app:setting {key} {value?} — get/set global settings.
 * Secrets print masked unless --show. --forget deletes the key.
 */
class AppSettingCommand extends Command
{
    protected $signature = 'app:setting {key} {value?} {--forget} {--show}';
    protected $description = 'Get or set a global app setting';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        if ($this->option('forget')) {
            AppSetting::where('key', $key)->delete();
            Cache::forget('app_settings:all');
            $this->info('Forgotten.');
            return 0;
        }
        $value = $this->argument('value');
        if ($value === null) {
            $v = Settings::get($key, null);
            if ($v === null) {
                $this->info('[not set]');
                return 0;
            }
            $secret = str_contains($key, 'secret') || str_contains($key, 'pass');
            $this->info($secret && !$this->option('show') ? '[set]' : (string) $v);
            return 0;
        }
        Settings::set($key, $value);
        $this->info('Saved.');
        return 0;
    }
}
