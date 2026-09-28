<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('user')->index();
            $table->string('name')->nullable();
            $table->text('message');
            $table->string('from_number');
            $table->string('type', 10)->default('sms'); // sms | mms
            $table->longText('media_data')->nullable(); // base64 for MMS
            $table->string('media_mime')->nullable();
            $table->string('media_size')->nullable();
            $table->dateTime('send_at')->index();
            $table->json('targets');      // { contacts, group_ids, company }
            $table->json('recipients');   // snapshot [{phone, name}]
            $table->string('status', 20)->default('pending'); // pending|sending|sent|partial|cancelled
            $table->json('send_log')->nullable(); // per-recipient results
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
