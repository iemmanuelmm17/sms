<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auto_replies', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('user')->index();
            $table->string('name');
            $table->json('keywords');                          // ["hours", "open"]
            $table->string('match_mode', 10)->default('any');  // any | all
            $table->text('message');                           // reply text
            $table->string('from_number')->nullable();         // null = receiving number
            $table->boolean('active')->default(true);
            $table->unsignedInteger('trigger_count')->default(0);
            $table->dateTime('last_triggered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_replies');
    }
};
