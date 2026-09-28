<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\DataChanged;
use App\Http\Controllers\Controller;
use App\Models\SentMessageLog;
use App\Models\TenantWebhook;
use App\Services\DynalinkService;
use App\Services\OptOutService;
use Illuminate\Http\Request;

/**
 * v1 tenant API — send SMS/MMS programmatically (Sanctum bearer token).
 * "Delivered" = accepted by the provider (2xx). No carrier DLRs exist.
 */
class MessageController extends Controller
{
    public function __construct(protected DynalinkService $dynalink, protected OptOutService $optouts) {}

    /** POST /api/v1/messages */
    public function send(Request $request)
    {
        $admin = $request->user();
        $tenant = $admin?->tenant;
        if (!$tenant || !$tenant->isActive()) {
            return response()->json(['message' => 'Tenant inactive.'], 403);
        }
        $domain = $tenant->domain;
        $user = $tenant->dynalink_user;
        $data = $request->validate([
            'to' => 'required|string|max:30',
            'from' => 'required|string|max:30',
            'message' => 'required|string|max:5000',
            'type' => 'sometimes|in:sms,mms',
            'data' => 'sometimes|string', // base64 for MMS
            'mime_type' => 'sometimes|string',
            'size' => 'sometimes|nullable|integer|min:0|max:1048576',
        ]);
        $to = preg_replace('/\D/', '', (string) $data['to']);
        $from = preg_replace('/\D/', '', (string) $data['from']);
        if (strlen($to) < 7 || strlen($to) > 15 || strlen($from) < 7 || strlen($from) > 15) {
            return response()->json(['message' => 'Invalid to/from number.'], 422);
        }
        // TCPA: never send to opted-out numbers.
        if ($this->optouts->isOptedOut($domain, $to, $from)) {
            return response()->json(['message' => 'Blocked: this number opted out (do-not-contact).'], 422);
        }
        $text = app(\App\Services\CompanySettingsService::class)->resolve($domain, $data['message'], 'API');
        $payload = ['type' => $data['type'] ?? 'sms', 'message' => $text,
            'destination' => $to, 'from-number' => $from];
        if (isset($data['data'])) $payload['data'] = $data['data'];
        if (isset($data['mime_type'])) $payload['mime-type'] = $data['mime_type'];
        if (isset($data['size'])) $payload['size'] = $data['size'];
        try {
            $token = $tenant->accessToken();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Provider unavailable.'], 503);
        }
        try {
            [$status, $body] = $this->dynalink->sendNew($token, $domain, $user, $payload);
        } catch (\Throwable $e) {
            return response()->json(['accepted' => false, 'message' => 'Provider error.'], 502);
        }
        $ok = $status >= 200 && $status < 300;
        $sessionId = is_array($body) ? ($body['messagesession-id'] ?? $body['messagesession_id'] ?? null) : null;
        if ($ok) {
            $tokenName = 'API';
            try { $tokenName = 'API: ' . ($admin->currentAccessToken()->name ?? 'token'); } catch (\Throwable $e) {}
            SentMessageLog::record([
                'tenant_id' => SentMessageLog::tenantIdFor($domain, $user),
                'domain' => $domain, 'user' => $user, 'agent_id' => null,
                'actor_name' => $tokenName, 'category' => SentMessageLog::NEW_SMS,
                'session_id' => $sessionId, 'from_number' => $from, 'to_number' => $to,
                'type' => $data['type'] ?? 'sms',
            ]);
            DataChanged::send($domain, $user, 'sessions', 'message-sent', null, ['remote' => $to]);
            TenantWebhook::fire($domain, $user, 'message.sent', [
                'session_id' => $sessionId, 'to' => $to, 'from' => $from,
                'type' => $data['type'] ?? 'sms', 'via' => 'api',
            ]);
            return response()->json(['accepted' => true, 'session_id' => $sessionId,
                'to' => $to, 'from' => $from, 'provider_status' => $status]);
        }
        return response()->json(['accepted' => false, 'provider_status' => $status,
            'provider_error' => is_array($body) ? $body : ['raw' => (string) $body]], 502);
    }
}
