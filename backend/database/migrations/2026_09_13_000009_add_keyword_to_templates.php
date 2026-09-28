<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Slash-command keyword for templates (/keyword in the compose box). */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('keyword', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('keyword');
        });
    }
};
