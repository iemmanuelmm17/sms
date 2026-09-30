<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared contacts: Dynalink keeps TWO address books per domain —
 * personal (/domains/{d}/users/{u}/contacts) and domain-level
 * (/domains/{d}/contacts, the shared book every user sees). The local
 * mirror gets a flag so both books can live in one table without the
 * personal sync ever treating shared rows as "deleted upstream".
 * Existing rows are personal (false) — the shared partition backfills
 * on first read / next resync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false)->after('provider_id');
            $table->index(['domain', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['domain', 'is_shared']);
            $table->dropColumn('is_shared');
        });
    }
};
