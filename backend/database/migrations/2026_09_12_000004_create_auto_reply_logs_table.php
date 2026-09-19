<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auto_reply_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_reply_id')->constrained('auto_replies')->cascadeOnDelete();
            $table->string('domain')->index();
            $table->string('user')->index();
            $table->string('from_number');
            $table->string('matched_keyword')->nullable();
            $table->string('status', 10)->default('sent'); // sent | failed
            $table->text('detail')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_logs');
    }
};
