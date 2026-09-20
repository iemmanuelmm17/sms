<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * First-run onboarding state: one JSON column per user
     * ({ dismissed, welcomed, tour_seen, steps: {<step>: iso-ts} }).
     * Nullable so existing rows read as "fresh" (all flags false);
     * `done` is derived from steps, never stored.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->json('onboarding')->nullable()->after('last_seen_at');
        });
        Schema::table('tenant_admins', function (Blueprint $table) {
            $table->json('onboarding')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('onboarding');
        });
        Schema::table('tenant_admins', function (Blueprint $table) {
            $table->dropColumn('onboarding');
        });
    }
};
