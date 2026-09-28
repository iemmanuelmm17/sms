<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a conversation be assigned to a PORTAL user.
     *
     * conversation_meta.agent_id is a FK to the legacy `agents` table. Portal
     * users live in `agent_identities`, so assigning one failed validation
     * ("exists:agents,id") with a 422 and the claim button did nothing.
     *
     * A second nullable column keeps the legacy FK intact — existing
     * assignments and Reporting history are untouched — while new claims
     * point at the identity.
     */
    public function up(): void
    {
        Schema::table('conversation_meta', function (Blueprint $t) {
            $t->foreignId('identity_id')->nullable()->after('agent_id')
                ->constrained('agent_identities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_meta', function (Blueprint $t) {
            $t->dropConstrainedForeignId('identity_id');
        });
    }
};
