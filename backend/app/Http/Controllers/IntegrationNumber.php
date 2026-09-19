<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row = one SMS number assigned to one integration.
 * The (domain, user, number) unique key enforces "a number can only
 * be assigned to 1 integration at a time" within a scope.
 */
class IntegrationNumber extends Model
{
    protected $table = 'integration_number_assignments';

    protected $fillable = ['integration_id', 'domain', 'user', 'number'];

    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }
}
