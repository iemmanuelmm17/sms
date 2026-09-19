<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('active');
            $table->boolean('is_deletable')->default(true)->after('is_default');
            $table->string('default_key', 30)->nullable()->after('is_deletable');
            $table->text('default_body')->nullable()->after('message');
            $table->json('default_keywords')->nullable()->after('default_body');
        });
    }

    public function down(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'is_deletable', 'default_key', 'default_body', 'default_keywords']);
        });
    }
};
