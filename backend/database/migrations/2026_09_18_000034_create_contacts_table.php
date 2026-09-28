<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local read-cache of the Dynalink address book. The app ALWAYS reads
        // contacts from here (fast, works when the provider is slow); the
        // provider is reached on write and on resync. `raw` keeps the original
        // provider payload so unknown fields survive a round-trip.
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 120);
            $table->string('user', 120);
            $table->string('provider_id', 120)->nullable(); // Dynalink unique-id / uid
            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('phone_work', 30)->nullable();
            $table->string('phone_cell', 30)->nullable();
            $table->string('phone_home', 30)->nullable();
            $table->string('phone_fax', 30)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable(); // last pull/push with the portal
            $table->timestamp('pushed_at')->nullable(); // last successful push to the portal
            $table->timestamps();

            $table->index(['domain', 'user']);
            // One local row per portal contact (NULL provider_id = local-only,
            // and MySQL treats NULLs as distinct, so many are allowed).
            $table->unique(['domain', 'user', 'provider_id'], 'contacts_tenant_provider_unique');
            $table->index(['domain', 'user', 'last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
