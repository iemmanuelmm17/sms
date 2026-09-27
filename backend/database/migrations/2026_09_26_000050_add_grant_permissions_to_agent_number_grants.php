<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fine-grained shared-number permissions. The row itself is the VIEW
        // grant (seeing the inbox); reply/create are the two opt-in actions.
        // Existing rows default to both ON, preserving the old "granted =
        // may reply and start new messages" behaviour for current users.
        Schema::table('agent_number_grants', function (Blueprint $table) {
            $table->boolean('reply')->default(true)->after('number');
            $table->boolean('create')->default(true)->after('reply');
        });
    }

    public function down(): void
    {
        Schema::table('agent_number_grants', function (Blueprint $table) {
            $table->dropColumn(['reply', 'create']);
        });
    }
};
