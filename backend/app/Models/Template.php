<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    /** List cache: shared templates leak across users, so the version is per-domain. */
    public static function listKey(string $domain, string $user): string
    {
        try { $v = (int) \Illuminate\Support\Facades\Cache::get("templates:ver:{$domain}", 1); }
        catch (\Throwable $e) { $v = 1; }
        return "templates:list:{$domain}:{$user}:v{$v}";
    }

    public static function bustList(string $domain): void
    {
        try {
            $k = "templates:ver:{$domain}";
            \Illuminate\Support\Facades\Cache::forever($k, (int) \Illuminate\Support\Facades\Cache::get($k, 1) + 1);
        } catch (\Throwable $e) {}
    }

    protected $fillable = ['domain', 'user', 'name', 'keyword', 'body', 'shared',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name'];

    protected $casts = ['shared' => 'boolean'];
}
