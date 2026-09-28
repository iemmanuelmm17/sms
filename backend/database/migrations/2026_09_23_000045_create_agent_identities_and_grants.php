<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Agents authenticated by the Dynalink portal.
         *
         * Deliberately holds NO credential: the portal owns the password, the
         * expiry cycle and the reset flow. This row exists only for the state
         * the portal does not know about — display name, tag colour, the
         * active/disabled kill switch, and onboarding progress.
         *
         * Keyed on (domain, ext) because the extension IS the identity as far
         * as the NS-API is concerned.
         */
        Schema::create('agent_identities', function (Blueprint $t) {
            $t->id();
            $t->string('domain');
            $t->string('ext', 32);                      // NS-API user, e.g. "6001"
            $t->string('display_name', 190)->nullable();
            $t->string('tag_color', 7)->default('#6366f1');
            $t->string('status', 16)->default('active'); // active | disabled
            // Same shape as agents.onboarding / tenant_admins.onboarding:
            // { steps: {name: iso}, welcomed: bool, tour_seen: bool, dismissed: bool }
            $t->json('onboarding')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('first_login_at')->nullable();
            $t->timestamps();
            $t->unique(['domain', 'ext']);
            $t->index(['domain', 'status']);
        });

        /**
         * Which SHARED numbers an extension may read and reply on.
         *
         * Own numbers (dest == ext) are never stored here — they are derived
         * live from the provider's number map, so provisioning changes in the
         * portal take effect without any local edit.
         *
         * A grant is necessary but not sufficient: the number must ALSO still
         * carry the shared flag. Un-sharing a number therefore revokes access
         * everywhere immediately without touching these rows.
         *
         * Rows may be created before the agent has ever logged in (admins can
         * pre-grant by typing an extension), so there is no FK to
         * agent_identities.
         */
        Schema::create('agent_number_grants', function (Blueprint $t) {
            $t->id();
            $t->string('domain');
            $t->string('ext', 32);
            $t->string('number', 32);                   // digits only
            $t->string('granted_by', 190)->nullable();  // audit breadcrumb
            $t->timestamps();
            $t->unique(['domain', 'ext', 'number']);
            $t->index(['domain', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_number_grants');
        Schema::dropIfExists('agent_identities');
    }
};
