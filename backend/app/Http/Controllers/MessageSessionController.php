<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Services\DynalinkService;
use App\Services\OptOutService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use App\Models\SentMessageLog;

class MessageSessionController extends Controller
{
    use ResolvesActor;
    public function __construct(protected DynalinkService $dynalink, protected OptOutService $optouts) {}

    protected function sess(Request $r): array
    {
        // Actor-shaped session (domain/user/display_name/role). No bearer here —
        // Dynalink call sites use $this->dtoken(request()) so local-only reads
        // never touch the provider.
        return $this->actor($r);
    }

    /** GET /api/sessions — list all message sessions. */
    public function index(Request $request)
    {
        $s = $this->sess($request);
        $sessions = $this->dynalink->sessions($this->dtoken(request()), $s['domain'], $s['user']);

        // Newest first
        usort($sessions, fn($a, $b) => strcmp(
            $b['messagesession-last-datetime'] ?? '', $a['messagesession-last-datetime'] ?? ''
        ));

        // Agents see shared-number threads plus threads on their own numbers
        // (filter AFTER the cache read — the cached list stays tenant-wide).
        if (($s['role'] ?? '') === 'agent' && !empty($s['agent_id'])
            && $agent = \App\Models\Agent::find($s['agent_id'])) {
            try {
                $allow = array_flip(array_merge($agent->assignedNumbers(),
                    app(\App\Services\CompanySettingsService::class)->sharedNumbers($s['domain'])));
                $sessions = array_values(array_filter($sessions, function ($sess) use ($allow) {
                    $d = preg_replace('/\D/', '', (string) ($sess['messagesession-sms-number'] ?? ''));
                    return $d !== '' && isset($allow[$d]);
                }));
            } catch (\Throwable $e) { /* fail-open: leave unfiltered */ }
        }

        return response()->json($sessions);
    }

    /** GET /api/sessions/{id}/messages */
    public function messages(Request $request, string $id)
    {
        $s = $this->sess($request);
        return response()->json(
            $this->dynalink->sessionMessages($this->dtoken(request()), $s['domain'], $s['user'], $id)
        );
    }

    /**
     * POST /api/sessions/{id}/messages — send SMS/MMS inside a session.
     * Body: { message, from-number, destination?: string|string[], type?: sms|mms,
     *         data?: base64, mime-type?: ..., size?: ... }
     */
    public function send(Request $request, string $id)
    {
        $s = $this->sess($request);
        $data = $request->validate([
            'message'      => 'required|string|max:5000',
            'from-number'  => 'required|string',
            'destination'  => 'sometimes',
            'type'         => 'sometimes|in:sms,mms',
            'data'         => 'sometimes|string',       // base64 for MMS
            'mime-type'    => 'sometimes|string',       // image/png|jpg|gif...
            'size'         => 'sometimes',
        ]);

        $this->assertAgentNumber($request, (string) ($data['from-number'] ?? ''));
        $data['message'] = app(\App\Services\CompanySettingsService::class)->resolve($s['domain'], $data['message'], (string) ($this->actor($request)['display_name'] ?? ''));

        // TCPA: never send to opted-out numbers.
        $check = isset($data['destination']) ? (array) $data['destination'] : [];
        if (empty($check)) {
            // In-session reply without explicit destination — resolve the remote.
            try {
                foreach ($this->dynalink->sessions($this->dtoken(request()), $s['domain'], $s['user']) as $sess) {
                    if ((string) ($sess['messagesession-id'] ?? '') === (string) $id) {
                        $check = [$sess['messagesession-remote'] ?? ''];
                        break;
                    }
                }
            } catch (\Throwable $e) { /* fail open; explicit paths are covered */ }
        }
        foreach ($check as $dest) {
            if ($dest !== '' && $this->optouts->isOptedOut($s['domain'], (string) $dest, (string) $data['from-number'])) {
                return response()->json(['message' => 'Blocked: this number opted out (do-not-contact).'], 422);
            }
        }

        $payload = [
            'type'        => $data['type'] ?? 'sms',
            'message'     => $data['message'],
            'from-number' => $data['from-number'],
        ];
        if (isset($data['destination'])) $payload['destination'] = $data['destination'];
        foreach (['data', 'mime-type', 'size'] as $k) {
            if (isset($data[$k])) $payload[$k] = $data[$k];
        }

        [$status, $body] = $this->dynalink->sendInSession(
            $this->dtoken(request()), $s['domain'], $s['user'], $id, $payload
        );

        if ($status >= 200 && $status < 300) {
            DataChanged::send($s['domain'], $s['user'], 'sessions', 'message-sent', $id, ['session_id' => $id]);
            $toDigits = preg_replace('/\D/', '', (string) ($check[0] ?? ''));
            SentMessageLog::record([
                'tenant_id' => SentMessageLog::scopeTenant($s),
                'domain' => $s['domain'], 'user' => $s['user'],
                'agent_id' => ($s['role'] ?? null) === 'agent' ? ($s['agent_id'] ?? null) : null,
                'actor_name' => $s['display_name'] ?? $s['username'] ?? null,
                'category' => SentMessageLog::REGULAR_REPLY,
                'session_id' => (string) $id,
                'from_number' => (string) ($data['from-number'] ?? ''),
                'to_number' => $toDigits !== '' ? $toDigits : null,
                'type' => $data['type'] ?? 'sms',
            ]);
        }
        return response()->json($body, $status);
    }

    /** POST /api/sessions/{id}/read — mark read in the portal + sync instances. */
    public function read(Request $request, string $id)
    {
        return $this->setStatus($request, $id, 'read');
    }

    /** POST /api/sessions/{id}/unread — mark unread in the portal + sync instances. */
    public function unread(Request $request, string $id)
    {
        return $this->setStatus($request, $id, 'unread');
    }

    protected function setStatus(Request $request, string $id, string $status)
    {
        $s = $this->sess($request);
        [$code] = $this->dynalink->setSessionStatus($this->dtoken(request()), $s['domain'], $s['user'], $id, $status);
        if ($code < 200 || $code >= 300) {
            return response()->json(['message' => "Portal update failed (HTTP {$code})."], 502);
        }
        DataChanged::send($s['domain'], $s['user'], 'sessions', $status, $id);
        return response()->json(['ok' => true]);
    }
}
