<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bulk TCPA wrap (company prefix + opt-out footer on 5+ recipients).
        // Defaults to true so existing behaviour is unchanged; turning it off
        // sends the message exactly as written.
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->boolean('tcpa_script')->default(true)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->dropColumn('tcpa_script');
        });
    }
};
