<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            // Login suffix: lowercase [a-z0-9-], e.g. "acme" in admin@acme.
            $table->string('name', 60)->unique();
            // Existing Dynalink identity + data scope (all scoped queries keep working).
            $table->string('domain', 190);
            $table->string('dynalink_user', 190);
            // Dynalink password, encrypted at rest via model cast. Never API-exposed.
            $table->text('dynalink_pass');
            $table->string('company_name', 190)->nullable();
            $table->string('status', 20)->default('active'); // active|deactivated
            $table->timestamps();
            // One tenant per Dynalink identity: scopes never overlap.
            $table->unique(['domain', 'dynalink_user']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
