<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $old = 'Reply STOP to unsubscribe.';
        $new = '$CompanyName: Notifications stopped. Reply START to subscribe.';
        // Only untouched rows (message still equals the old default) are updated;
        // user-edited bodies are left alone. default_body always tracks latest.
        DB::table('auto_replies')->where('default_key', 'opt_out')->where('message', $old)->update(['message' => $new]);
        DB::table('auto_replies')->where('default_key', 'opt_out')->update(['default_body' => $new]);
    }

    public function down(): void
    {
        // Non-reversible content change (edits may exist); restore manually if needed.
    }
};
