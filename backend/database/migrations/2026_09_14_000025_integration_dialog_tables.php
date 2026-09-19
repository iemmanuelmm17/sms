<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Integration dialog support:
     * - number assignments (one integration per number, per scope),
     * - 60-minute dialog sessions keyed by integration + customer,
     * - admin-customized message ("spiel") overrides on integrations.
     */
    public function up(): void
    {
        Schema::create('integration_number_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('domain', 190);
            $table->string('user', 190);
            $table->string('number', 40); // digits only
            $table->timestamps();
            $table->unique(['domain', 'user', 'number']);
        });

        Schema::create('integration_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('phone', 40); // customer digits
            $table->string('state', 30)->default('menu');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['integration_id', 'phone']);
            $table->index('expires_at');
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->json('spiels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('spiels');
        });
        Schema::dropIfExists('integration_sessions');
        Schema::dropIfExists('integration_number_assignments');
    }
};
