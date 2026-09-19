<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shared-inbox agents (first/last name + tag color for avatar overlays).
        Schema::create('agents', function (Blueprint $t) {
            $t->id();
            $t->string('domain');
            $t->string('user');
            $t->string('first_name', 60);
            $t->string('last_name', 60);
            $t->string('tag_color', 7)->default('#6366f1');
            $t->timestamps();
            $t->index(['domain', 'user']);
        });

        // Local per-conversation state keyed by Dynalink messagesession-id:
        // agent assignment + pinned threads.
        Schema::create('conversation_meta', function (Blueprint $t) {
            $t->id();
            $t->string('domain');
            $t->string('user');
            $t->string('session_id');
            $t->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $t->boolean('pinned')->default(false);
            $t->timestamps();
            $t->unique(['domain', 'user', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_meta');
        Schema::dropIfExists('agents');
    }
};
