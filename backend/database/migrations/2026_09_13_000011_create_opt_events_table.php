<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opt_events', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 120)->index();
            $table->string('phone_number', 30)->index();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('direction', 10); // opt_in | opt_out
            $table->string('keyword', 30)->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opt_events');
    }
};
