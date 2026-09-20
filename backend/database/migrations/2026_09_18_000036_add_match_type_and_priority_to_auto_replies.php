<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-rule match type ('keyword' | 'any' catch-all) + explicit
        // priority order. Lower number = checked first; the first eligible
        // rule wins and is the only one that replies.
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->string('match_type', 10)->default('keyword')->after('match_mode');
            $table->unsignedInteger('priority')->default(0)->after('match_type');
            $table->index(['domain', 'user', 'priority'], 'auto_replies_tenant_priority_index');
        });
    }

    public function down(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->dropIndex('auto_replies_tenant_priority_index');
            $table->dropColumn(['match_type', 'priority']);
        });
    }
};
