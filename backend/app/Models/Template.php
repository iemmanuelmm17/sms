<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $fillable = ['domain', 'user', 'name', 'keyword', 'body', 'shared',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name'];

    protected $casts = ['shared' => 'boolean'];
}
