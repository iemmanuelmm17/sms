<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - integration_sessions.data: pending dialog context (e.g. the
     *   account awaiting its billing-code check).
     * - integrations.settings: provider settings (Rev.io journal note
     *   + optional Rev.io user id).
     */
    public function up(): void
    {
        Schema::table('integration_sessions', function (Blueprint $table) {
            $table->json('data')->nullable();
        });
        Schema::table('integrations', function (Blueprint $table) {
            $table->json('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integration_sessions', function (Blueprint $table) {
            $table->dropColumn('data');
        });
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
