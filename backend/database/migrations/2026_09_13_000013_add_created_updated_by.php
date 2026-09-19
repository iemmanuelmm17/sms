<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audit identifiers: which login created/updated each item (+ display-name snapshot). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['templates', 'auto_replies', 'scheduled_messages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('created_by')->nullable();
                $t->string('created_by_name')->nullable();
                $t->string('updated_by')->nullable();
                $t->string('updated_by_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['templates', 'auto_replies', 'scheduled_messages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['created_by', 'created_by_name', 'updated_by', 'updated_by_name']);
            });
        }
    }
};
