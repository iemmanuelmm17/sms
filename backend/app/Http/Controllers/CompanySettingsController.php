<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Services\CompanySettingsService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/** Per-domain company settings (company name for /company + $CompanyName). */
class CompanySettingsController extends Controller
{
    use ResolvesActor;
    public function __construct(protected CompanySettingsService $settings) {}

    protected function sess(Request $r): array
    {
        // Actor-shaped session (domain/user/display_name/role). No bearer here —
        // Dynalink call sites use $this->dtoken(request()) so local-only reads
        // never touch the provider.
        return $this->actor($r);
    }

    /** GET /api/company-settings */
    public function show(Request $request)
    {
        $s = $this->sess($request);
        $out = $this->settings->get($s['domain']);
        try {
            $out['main_number'] = preg_replace('/\D/', '', (string) (\App\Models\Tenant::where('domain', $s['domain'])
                ->where('dynalink_user', $s['user'])->value('main_number') ?? ''));
        } catch (\Throwable $e) { $out['main_number'] = ''; }
        return response()->json($out);
    }

    /** PUT /api/company-settings { company_name?, auto_reply_cooldown_minutes?, number_email?, number_shared? } */
    public function update(Request $request)
    {
        $this->requireAdmin($request);
        $s = $this->sess($request);
        $data = $request->validate([
            'company_name' => 'nullable|string|max:120',
            'auto_reply_cooldown_minutes' => 'sometimes|nullable|integer|min:0|max:1440',
            'number_email' => 'sometimes|array',
            'number_email.*' => 'sometimes|array',
            'number_email.*.notify' => 'sometimes|array|max:10',
            'number_email.*.notify.*' => 'email|max:190',
            'number_email.*.enabled' => 'sometimes|boolean',
            'number_shared' => 'sometimes|array',
            'number_shared.*' => 'boolean',
        ]);
        // Preserve the existing name when only the cooldown is sent.
        $prev = $this->settings->get($s['domain']);
        $saved = $this->settings->set($s['domain'],
            array_key_exists('company_name', $data) ? (string) $data['company_name'] : $prev['company_name'],
            $data['auto_reply_cooldown_minutes'] ?? null);
        if (array_key_exists('number_email', $data) && is_array($data['number_email'])) {
            foreach ($data['number_email'] as $k => $cfg) {
                $d = preg_replace('/\D/', '', (string) $k);
                if (strlen($d) < 7 || strlen($d) > 15) {
                    return response()->json(['message' => 'Invalid SMS number key.'], 422);
                }
                $en = array_key_exists('enabled', (array) $cfg) ? (bool) $cfg['enabled'] : ($prev['number_email'][$d]['enabled'] ?? true);
                $this->settings->setNumberEmail($s['domain'], $d, (array) ($cfg['notify'] ?? []), $en);
            }
            $saved = $this->settings->get($s['domain']);
        }
        if (array_key_exists('number_shared', $data) && is_array($data['number_shared'])) {
            foreach ($data['number_shared'] as $k => $v) {
                $d = preg_replace('/\D/', '', (string) $k);
                if (strlen($d) < 7 || strlen($d) > 15) {
                    return response()->json(['message' => 'Invalid SMS number key.'], 422);
                }
                $this->settings->setNumberShared($s['domain'], $d, (bool) $v);
            }
            $saved = $this->settings->get($s['domain']);
        }
        if (array_key_exists('company_name', $data) && (string) $data['company_name'] !== (string) ($prev['company_name'] ?? '')) {
            $this->audit($request, 'company-settings.updated', ['company_name' => $saved['company_name'] ?? '']);
        }
        if (array_key_exists('number_shared', $data) && is_array($data['number_shared'])) {
            foreach ($data['number_shared'] as $k => $v) {
                $d = preg_replace('/\D/', '', (string) $k);
                if (strlen($d) < 7 || strlen($d) > 15) continue;
                if ((bool) $v !== (bool) ($prev['number_shared'][$d] ?? false)) {
                    $this->audit($request, 'number.shared-changed', ['number' => $d, 'shared' => (bool) $v]);
                }
            }
        }
        if (array_key_exists('number_email', $data) && is_array($data['number_email'])) {
            foreach ($data['number_email'] as $k => $cfg) {
                $d = preg_replace('/\D/', '', (string) $k);
                if (strlen($d) < 7 || strlen($d) > 15) continue;
                $pcfg = (array) ($prev['number_email'][$d] ?? []);
                $now = $saved['number_email'][$d] ?? null;
                $wasList = array_values((array) ($pcfg['notify'] ?? []));
                $nowList = $now === null ? [] : array_values((array) ($now['notify'] ?? []));
                $a = $nowList; $b = $wasList; sort($a); sort($b);
                if ($a !== $b) {
                    $this->audit($request, 'number.notify-changed', ['number' => $d, 'notify' => $nowList]);
                }
                $wasEn = ($pcfg['enabled'] ?? true) !== false;
                $nowEn = $now === null ? true : (($now['enabled'] ?? true) !== false);
                if ($nowEn !== $wasEn) {
                    $this->audit($request, 'number.email-toggled', ['number' => $d, 'enabled' => $nowEn]);
                }
            }
        }
        DataChanged::send($s['domain'], $s['user'], 'company-settings', 'saved');
        return response()->json($saved);
    }
}
