<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Include opt-in contacts": when true, every number whose latest TCPA
        // action was an opt-in (START) is ADDED to the recipient list on top of
        // the contacts / groups / CSV / company you selected. Default false
        // keeps today's behaviour — only the people you picked.
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->boolean('include_optin')->default(false)->after('optin_only');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->dropColumn('include_optin');
        });
    }
};
