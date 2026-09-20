<?php

namespace App\Models;

use App\Services\DynalinkService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant (login suffix in name@tenant). Owns one Dynalink identity whose
 * stored credential mints the API token for every tenant session.
 * (domain, dynalink_user) matches the pre-existing data scope, so all
 * scoped queries keep working unchanged.
 */
class Tenant extends Model
{
    protected $fillable = ['name', 'domain', 'dynalink_user', 'dynalink_pass',
        'main_number', 'company_name', 'status', 'password_expiry_days'];

    /** The Dynalink password is never serialized to any API response. */
    protected $hidden = ['dynalink_pass'];

    protected $casts = ['dynalink_pass' => 'encrypted', 'password_expiry_days' => 'integer'];

    public function admins()
    {
        return $this->hasMany(TenantAdmin::class);
    }

    public function isActive(): bool
    {
        return ($this->status ?: 'active') === 'active';
    }

    /**
     * Dynalink access token via the tenant's stored credential (cached ~50 min).
     * Also refreshes the server-side refresh-token copy the webhook uses.
     */
    public function accessToken(): string
    {
        return Cache::remember("dynalink:tenant_token:{$this->id}", 3000, function () {
            $tokens = app(DynalinkService::class)->login(
                $this->dynalink_user . '@' . $this->domain, $this->dynalink_pass);
            if (!empty($tokens['refresh_token'])) {
                Cache::put("dynalink:rt:{$this->domain}:{$this->dynalink_user}",
                    encrypt($tokens['refresh_token']), now()->addDays(30));
            }
            return $tokens['access_token'];
        });
    }

    public function forgetToken(): void
    {
        Cache::forget("dynalink:tenant_token:{$this->id}");
    }
}
