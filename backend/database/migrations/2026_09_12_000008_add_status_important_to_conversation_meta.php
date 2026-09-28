<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_meta', function (Blueprint $t) {
            $t->string('status', 10)->default('active')->after('pinned'); // active | archived | spam
            $t->boolean('important')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_meta', function (Blueprint $t) {
            $t->dropColumn(['status', 'important']);
        });
    }
};
