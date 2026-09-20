<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Send to opt-in numbers": when true, a recipient only receives the
        // message if their latest TCPA action was an opt-in (START). Default
        // false keeps today's behaviour — everyone who hasn't opted out.
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->boolean('optin_only')->default(false)->after('tcpa_script');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->dropColumn('optin_only');
        });
    }
};
