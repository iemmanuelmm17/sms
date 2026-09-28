<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\Integration;
use App\Models\IntegrationNumber;
use App\Models\IntegrationSession;
use App\Services\IntegrationSpiels;
use App\Services\RevioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Third-party integrations (tenant portal, admins only).
 * Rev.io is the first provider. Credentials are verified against
 * Rev.io BEFORE anything is saved, and a failed check never
 * overwrites known-good stored credentials. Passwords/tokens are
 * never included in any response — see entry().
 */
class IntegrationController extends Controller
{
    use ResolvesActor;

    public function __construct(protected RevioService $revio) {}

    /** Public shape: status + identifiers only, never secrets. */
    protected function entry(?Integration $row, string $provider, string $label): array
    {
        return [
            'provider' => $provider,
            'label' => Integration::labelFor($provider),
            'numbers' => $row ? $row->numbers()->pluck('number')->all() : [],
            'spiels' => IntegrationSpiels::merged($row, $provider),
            'spiels_customized' => IntegrationSpiels::customizedKeys($row),
            'spiel_meta' => IntegrationSpiels::meta($provider),
            'spiel_defaults' => IntegrationSpiels::defaults($provider),
            'settings' => $row
                ? ['revio_note' => $row->revioNote(), 'revio_user_id' => $row->revioUserId(),
                    'code_max_attempts' => $row->codeMaxAttempts(),
                    'code_lockout_minutes' => $row->codeLockoutMinutes(),
                    'hours' => $row->businessHours(),
                    'ticket_group_id' => $row->ticketGroupId(),
                    'ticket_type_id' => $row->ticketTypeId(),
                    'ticket_step_id' => $row->ticketStepId()]
                : ['revio_note' => Integration::DEFAULT_REVIO_NOTE, 'revio_user_id' => null,
                    'code_max_attempts' => Integration::DEFAULT_CODE_ATTEMPTS,
                    'code_lockout_minutes' => Integration::DEFAULT_CODE_LOCKOUT_MINUTES,
                    'hours' => Integration::DEFAULT_HOURS,
                    'ticket_group_id' => Integration::DEFAULT_TICKET_GROUP_ID,
                    'ticket_type_id' => Integration::DEFAULT_TICKET_TYPE_ID,
                    'ticket_step_id' => Integration::DEFAULT_TICKET_STEP_ID],
            'configured' => $row !== null && $row->password !== null && $row->password !== '',
            'username' => $row?->username ?? '',
            'client_code' => $row?->client_code ?? '',
            'status' => $row?->status ?? 'unconfigured',
            'last_checked_at' => $row?->last_checked_at?->toDateTimeString(),
            'last_error' => $row?->last_error,
        ];
    }

    /** GET /api/integrations — status of every provider for this scope. */
    public function index(Request $request)
    {
        $s = $this->actor($request);
        $this->requireAdmin($s);
        return response()->json([
            'providers' => [
                $this->entry(Integration::forScope($s['domain'], $s['user'], Integration::PROVIDER_REVIO),
                    Integration::PROVIDER_REVIO, 'Rev.io'),
            ],
        ]);
    }

    /**
     * PUT /api/integrations/revio — verify then save.
     * Blank password keeps the stored one (username/client-code-only
     * update); without stored credentials all three fields are required.
     */
    public function saveRevio(Request $request)
    {
        $s = $this->actor($request);
        $this->requireAdmin($s);
        $data = $request->validate([
            'username' => 'required|string|max:190',
            'password' => 'sometimes|nullable|string|max:200',
            'client_code' => 'required|string|max:190',
        ]);
        $row = Integration::forScope($s['domain'], $s['user'], Integration::PROVIDER_REVIO);
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $password = (string) ($row?->password ?? '');
            if ($password === '') {
                return response()->json(['message' => 'Password is required.'], 422);
            }
        }
        try {
            [$status] = $this->revio->systemStatus(trim($data['username']), trim($data['client_code']), $password);
        } catch (\Throwable $e) {
            Log::warning('Rev.io check transport failure: ' . $e->getMessage());
            return response()->json(['message' => "Couldn't reach Rev.io — check your connection and retry.",
                'detail' => substr($e->getMessage(), 0, 300)], 503);
        }
        if ($status !== 200) {
            // Invalid — stored credentials (if any) are left untouched.
            return response()->json(['message' => 'Invalid Rev.io credentials.'], 422);
        }
        $row = Integration::updateOrCreate(
            ['domain' => $s['domain'], 'user' => $s['user'], 'provider' => Integration::PROVIDER_REVIO],
            ['username' => trim($data['username']), 'password' => $password,
                'client_code' => trim($data['client_code']),
                'status' => 'connected', 'last_checked_at' => now(), 'last_error' => null]
        );
        $this->audit($request, 'integration.saved', ['provider' => Integration::PROVIDER_REVIO]);
        return response()->json($this->entry($row, Integration::PROVIDER_REVIO, 'Rev.io'));
    }

    /** POST /api/integrations/revio/test — re-check saved credentials. */
    public function testRevio(Request $request)
    {
        $s = $this->actor($request);
        $this->requireAdmin($s);
        $row = Integration::forScope($s['domain'], $s['user'], Integration::PROVIDER_REVIO);
        if (!$row || !$row->password) {
            return response()->json(['message' => 'Rev.io is not configured.'], 422);
        }
        try {
            [$status] = $this->revio->systemStatus($row->username, $row->client_code, $row->password);
        } catch (\Throwable $e) {
            Log::warning('Rev.io test transport failure: ' . $e->getMessage());
            $row->update(['status' => 'error', 'last_checked_at' => now(),
                'last_error' => 'Could not reach Rev.io: ' . substr($e->getMessage(), 0, 300)]);
            return response()->json($this->entry($row->fresh(), Integration::PROVIDER_REVIO, 'Rev.io'), 503);
        }
        $row->update($status === 200
            ? ['status' => 'connected', 'last_checked_at' => now(), 'last_error' => null]
            : ['status' => 'error', 'last_checked_at' => now(), 'last_error' => 'Rev.io rejected the credentials.']);
        return response()->json($this->entry($row->fresh(), Integration::PROVIDER_REVIO, 'Rev.io'));
    }

    /** DELETE /api/integrations/revio — disconnect (idempotent). */
    public function destroyRevio(Request $request)
    {
        $s = $this->actor($request);
        $this->requireAdmin($s);
        $ids = Integration::where('domain', $s['domain'])->where('user', $s['user'])
            ->where('provider', Integration::PROVIDER_REVIO)->pluck('id');
        IntegrationNumber::whereIn('integration_id', $ids)->delete();
        IntegrationSession::whereIn('integration_id', $ids)->delete();
        Integration::whereIn('id', $ids)->delete();
        $this->audit($request, 'integration.disconnected', ['provider' => Integration::PROVIDER_REVIO]);
        return response()->json(['ok' => true]);
    }

    /**
     * PUT /api/integrations/revio/numbers — replace-all assignment.
     * A number can belong to only one integration per scope.
     */
    public function numbersRevio(Request $request)
    {
        $s = $this->actor($request);
        $this->requireAdmin($s);
        $data = $request->validate([
            'numbers' => 'sometimes|array',
            'numbers.*' => 'string|max:40',
        ]);
        $row = Integration::forScope($s['domain'], $s['user'], Integration::PROVIDER_REVIO);
        if (!$row || !$row->password) {
            return response()->json(['message' => 'Rev.io is not configured.'], 422);
        }
        $digits = [];
        foreach ((array) ($data['numbers'] ?? []) as $n) {
            $d = preg_replace('/\D/', '', (string) $n);
            if ($d !== '') $digits[$d] = true;
        }
        $digits = array_keys($digits);
        $conflict = IntegrationNumber::where('domain', $s['domain'])->where('user', $s['user'])
            ->whereIn('number', $digits)->where('integration_id', '!=', $row->id)->with('integration')->first();
        if ($conflict) {
            $label = Integration::labelFor($conflict->integration?->provider ?? 'another');
            return response()->json(['message' => "Number {$conflict->number} is already assigned to {$label}."], 422);
        }
        $row->numbers()->whereNotIn('number', $digits)->delete();
        $have = $row->numbers()->pluck('number')->all();
        foreach (array_diff($digits, $have) as $d) {
            $row->numbers()->create(['domain' => $s['domain'], 'user' => $s['user'], 'number' => $d]);
        }
        $this->audit($request, 'integration.numbers',
            ['provider' => Integration::PROVIDER_REVIO, 'count' => count($digits)]);
        return response()->json($this->entry($row->fresh(), Integration::PROVIDER_REVIO, 'Rev.io'));
    }

    /**
     * PUT /api/integrations/revio/spiels — admin message overrides.
     * Unknown keys are ignored; a blank value resets to the default.
     */
    public function spielsRevio(Request $request)
    {
        $s = $this->actor($request);
        $this->requireAdmin($s);
        $data = $request->validate(['spiels' => 'sometimes|array']);
        $row = Integration::forScope($s['domain'], $s['user'], Integration::PROVIDER_REVIO);
        if (!$row || !$row->password) {
            return response()->json(['message' => 'Rev.io is not configured.'], 422);
        }
        $keys = IntegrationSpiels::keys(Integration::PROVIDER_REVIO);
        $defaults = IntegrationSpiels::defaults(Integration::PROVIDER_REVIO);
        $custom = is_array($row->spiels) ? $row->spiels : [];
        foreach ((array) ($data['spiels'] ?? []) as $k => $v) {
            if (!in_array($k, $keys, true)) continue;
            $t = trim((string) $v);
            // Blank — or identical to the default — removes the override.
            if ($t === '' || $t === ($defaults[$k] ?? null)) unset($custom[$k]);
            else $custom[$k] = mb_substr($t, 0, 1000);
        }
        $row->update(['spiels' => $custom]);
        $this->audit($request, 'integration.spiels', ['provider' => Integration::PROVIDER_REVIO]);
        return response()->json($this->entry($row->fresh(), Integration::PROVIDER_REVIO, 'Rev.io'));
    }

    /**
     * PUT /api/integrations/revio/settings — Rev.io journal settings.
     * Note is required (blank restores the default); user id is optional.
     */
    public function settingsRevio(Request $request)
    {
        $s = $this->actor($request);
        $this->requireAdmin($s);
        $data = $request->validate([
            'revio_note' => 'sometimes|nullable|string|max:500',
            'revio_user_id' => 'sometimes|nullable|integer|min:1|max:2147483647',
            'code_max_attempts' => 'sometimes|nullable|integer|min:1|max:10',
            'code_lockout_minutes' => 'sometimes|nullable|integer|min:1|max:1440',
            'ticket_group_id' => 'sometimes|nullable|integer|min:0|max:2147483647',
            'ticket_type_id' => 'sometimes|nullable|integer|min:0|max:2147483647',
            'ticket_step_id' => 'sometimes|nullable|integer|min:0|max:2147483647',
            'hours' => 'sometimes|array',
            'hours.*.open' => 'sometimes|boolean',
            'hours.*.start' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'hours.*.end' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);
        $row = Integration::forScope($s['domain'], $s['user'], Integration::PROVIDER_REVIO);
        if (!$row || !$row->password) {
            return response()->json(['message' => 'Rev.io is not configured.'], 422);
        }
        $settings = is_array($row->settings) ? $row->settings : [];
        if (array_key_exists('revio_note', $data)) {
            $note = trim((string) ($data['revio_note'] ?? ''));
            if ($note === '') unset($settings['revio_note']);
            else $settings['revio_note'] = mb_substr($note, 0, 500);
        }
        if (array_key_exists('revio_user_id', $data)) {
            $uid = $data['revio_user_id'];
            if ($uid === null || $uid === '') unset($settings['revio_user_id']);
            else $settings['revio_user_id'] = (int) $uid;
        }
        if (array_key_exists('code_max_attempts', $data)) {
            $v = (int) ($data['code_max_attempts'] ?? 0);
            if ($v >= 1) $settings['code_max_attempts'] = min($v, 10);
            else unset($settings['code_max_attempts']);
        }
        if (array_key_exists('code_lockout_minutes', $data)) {
            $v = (int) ($data['code_lockout_minutes'] ?? 0);
            if ($v >= 1) $settings['code_lockout_minutes'] = min($v, 1440);
            else unset($settings['code_lockout_minutes']);
        }
        if (array_key_exists('hours', $data) && is_array($data['hours'])) {
            $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
            $hours = is_array($settings['hours'] ?? null) ? $settings['hours'] : [];
            foreach ($days as $day) {
                if (!is_array($data['hours'][$day] ?? null)) continue;
                $in = $data['hours'][$day];
                $hours[$day] = [
                    'open' => (bool) ($in['open'] ?? false),
                    'start' => $in['start'] ?? '09:00',
                    'end' => $in['end'] ?? '17:00',
                ];
            }
            $settings['hours'] = $hours;
        }
        foreach (['ticket_group_id', 'ticket_type_id', 'ticket_step_id'] as $k) {
            if (!array_key_exists($k, $data)) continue;
            $v = $data[$k];
            if ($v === null || $v === '') unset($settings[$k]);
            else $settings[$k] = min((int) $v, 2147483647);
        }
        $row->update(['settings' => $settings]);
        $this->audit($request, 'integration.settings', ['provider' => Integration::PROVIDER_REVIO]);
        return response()->json($this->entry($row->fresh(), Integration::PROVIDER_REVIO, 'Rev.io'));
    }
}
