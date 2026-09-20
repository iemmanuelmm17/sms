<?php

namespace App\Http\Controllers;

use App\Services\Branding;

/** Public branding (name + logo): needed pre-login, so no auth. */
class BrandingController extends Controller
{
    /** GET /api/branding */
    public function show()
    {
        return response()->json(Branding::state());
    }

    /** GET /api/branding/logo — long-cacheable; the ?v= param busts on change. */
    public function logo()
    {
        $path = Branding::logoPath();
        abort_unless(is_string($path), 404);
        return response()->file($path, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
