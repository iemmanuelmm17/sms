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

    /**
     * GET /api/realtime — public realtime-connection config (pre-login, like
     * branding) so the SPA can point Laravel Echo at the right Reverb server
     * at runtime. Values: DB override (Super → Settings) → .env. The Reverb
     * APP KEY is a PUBLIC client key by design (the secret stays server-side),
     * so it is safe to serve here. Empty fields come back null so the client
     * falls back to its build-time .env value.
     */
    public function realtime()
    {
        $c = config('broadcasting.connections.reverb', []);
        // Prefer the SDK-facing `options` block (source of truth for the
        // broadcaster), fall back to the legacy top-level mirrors.
        $o = is_array($c['options'] ?? null) ? $c['options'] : [];
        $s = fn ($v) => (is_string($v) || is_numeric($v)) ? trim((string) $v) : '';
        return response()->json([
            'host' => $this->orNull($s($o['host'] ?? $c['host'] ?? null)),
            'port' => $this->orNullInt($o['port'] ?? $c['port'] ?? null),
            'scheme' => $this->orNull($s($o['scheme'] ?? $c['scheme'] ?? null)),
            'app_key' => $this->orNull($s($c['key'] ?? null)),
        ]);
    }

    protected function orNull(?string $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v !== '' ? $v : null;
    }

    protected function orNullInt(mixed $v): ?int
    {
        $i = (int) ($v ?? 0);
        return $i > 0 ? $i : null;
    }

    /** GET /api/branding/logo — long-cacheable; the ?v= param busts on change. */
    public function logo()
    {
        $path = Branding::logoPath();
        abort_unless(is_string($path), 404);
        return response()->file($path, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // Neutralizes any legacy/inline SVG: no scripts, no same-origin
            // powers, render-only in a sandboxed context.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
