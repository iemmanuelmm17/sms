<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Services\DynalinkService;
use App\Services\OptOutService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use App\Models\SentMessageLog;

class MessageController extends Controller
{
    use ResolvesActor;
    /**
     * NS-API user to post a new message as.
     *
     * A message must be sent by the extension that OWNS the from-number. For
     * a granted SHARED number that is someone else, so posting as the caller's
     * own extension makes the provider reject it as an invalid from-number.
     * Admins and legacy sessions keep their existing scope.
     */
    protected function senderFor(Request $request, array $s, ?string $fromNumber): string
    {
        $digits = preg_replace('/\D/', '', (string) $fromNumber);
        if ($digits === '') return $s['user'];
        try {
            $owner = app(\App\Services\DynalinkService::class)
                ->numberOwner($this->dtoken($request), $s['domain'], $digits);
            if ($owner !== null && $owner !== '') return $owner;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('senderFor: owner lookup failed', [
                'domain' => $s['domain'], 'number' => $digits, 'error' => $e->getMessage(),
            ]);
        }
        return $s['user'];   // fall back rather than block the send
    }

    public function __construct(protected DynalinkService $dynalink, protected OptOutService $optouts) {}

    protected function sess(Request $r): array
    {
        // Actor-shaped session (domain/user/display_name/role). No bearer here —
        // Dynalink call sites use $this->dtoken(request()) so local-only reads
        // never touch the provider.
        return $this->actor($r);
    }

    /**
     * POST /api/messages — new outbound message (API generates session).
     * Body: { message, destination: string, from-number: string,
     *         type?: sms|mms, data?: base64, mime-type?:, size?: }
     */
    public function store(Request $request)
    {
        $s = $this->sess($request);
        $actor = $this->actor($request);
        $data = $request->validate([
            'message'     => 'required|string|max:5000',
            'destination' => 'required|string',
            'from-number' => 'required|string',
            'type'        => 'sometimes|in:sms,mms',
            'data'        => 'sometimes|string',
            'mime-type'   => 'sometimes|string',
            'size'        => 'sometimes|nullable|integer|min:0|max:1048576',
        ]);

        $this->assertMediaSize($data['data'] ?? null);
        $this->assertAgentNumber($request, (string) ($data['from-number'] ?? ''));
        $data['message'] = app(\App\Services\CompanySettingsService::class)->resolve($s['domain'], $data['message'], (string) ($this->actor($request)['display_name'] ?? ''));

        // TCPA: never send to opted-out numbers.
        if ($this->optouts->isOptedOut($s['domain'], (string) $data['destination'], (string) $data['from-number'])) {
            return response()->json(['message' => 'Blocked: this number opted out (do-not-contact).'], 422);
        }

        $payload = [
            'type'        => $data['type'] ?? 'sms',
            'message'     => $data['message'],
            'destination' => $data['destination'],
            'from-number' => $data['from-number'],
        ];
        foreach (['data', 'mime-type', 'size'] as $k) {
            if (isset($data[$k])) $payload[$k] = $data[$k];
        }

        [$status, $body] = $this->dynalink->sendNew(
            $this->dtoken(request()), $s['domain'],
            $this->senderFor($request, $s, $payload['from-number'] ?? null), $payload
        );

        if ($status >= 200 && $status < 300) {
            DataChanged::send($s['domain'], $s['user'], 'sessions', 'message-sent', null, [
                'remote' => preg_replace('/\D/', '', (string) $data['destination']),
            ]);
            \App\Services\OnboardingService::markAgentStep($s, 'first_send');
            SentMessageLog::record([
                'tenant_id' => SentMessageLog::scopeTenant($s),
                'domain' => $s['domain'], 'user' => $s['user'],
                'agent_id' => ($s['role'] ?? null) === 'agent' ? ($s['agent_id'] ?? null) : null,
                'actor_name' => $s['display_name'] ?? $s['username'] ?? null,
                'category' => SentMessageLog::NEW_SMS,
                'session_id' => is_array($body) ? ($body['messagesession-id'] ?? $body['messagesession_id'] ?? null) : null,
                'from_number' => (string) $data['from-number'],
                'to_number' => preg_replace('/\D/', '', (string) $data['destination']),
                'type' => $data['type'] ?? 'sms',
            ]);
        }
        return response()->json($body, $status);
    }

    /**
     * POST /api/messages/bulk — ONE message to MULTIPLE numbers in a SINGLE call.
     * Dynalink accepts `destination` as an array on the session endpoint.
     * Body: { message, destinations: string[], from-number, type?, data?, mime-type?, size? }
     * NOTE: scheduled sends still go 1-by-1 via SendScheduledMessage jobs.
     */
    public function bulk(Request $request)
    {
        $s = $this->sess($request);
        $actor = $this->actor($request);
        $data = $request->validate([
            'message'      => 'required|string|max:5000',
            'destinations' => 'required|array|min:1|max:500',
            'destinations.*' => 'required|string',
            'from-number'  => 'required|string',
            'type'         => 'sometimes|in:sms,mms',
            'data'         => 'sometimes|string',
            'mime-type'    => 'sometimes|string',
            'size'         => 'sometimes|nullable|integer|min:0|max:1048576',
        ]);

        $this->assertMediaSize($data['data'] ?? null);
        $this->assertAgentNumber($request, (string) ($data['from-number'] ?? ''));
        $data['message'] = app(\App\Services\CompanySettingsService::class)->resolve($s['domain'], $data['message'], (string) ($this->actor($request)['display_name'] ?? ''));

        $dests = array_values(array_unique(array_map(
            fn($d) => preg_replace('/\D/', '', (string) $d),
            $data['destinations']
        )));
        $dests = array_values(array_filter($dests));
        if (empty($dests)) {
            return response()->json(['message' => 'No valid destination numbers.'], 422);
        }
        // TCPA: check every recipient FIRST — opted-out numbers are removed
        // (with reasons) and the rest still send.
        $from = (string) $data['from-number'];
        $skipped = [];
        $dests = array_values(array_filter($dests, function ($d) use ($s, $from, &$skipped) {
            if ($this->optouts->isOptedOut($s['domain'], $d, $from)) {
                $skipped[] = ['phone' => $d, 'reason' => 'opted out (do-not-contact)'];
                return false;
            }
            return true;
        }));
        if (empty($dests)) {
            return response()->json(['message' => 'All recipients opted out (do-not-contact).', 'skipped' => $skipped], 422);
        }

        $mms = [];
        foreach (['data', 'mime-type', 'size'] as $k) {
            if (isset($data[$k])) $mms[$k] = $data[$k];
        }

        // Single destination → plain new-message call.
        if (count($dests) === 1) {
            [$status, $body] = $this->dynalink->sendNew($this->dtoken(request()), $s['domain'],
                $this->senderFor($request, $s, $data['from-number'] ?? null), [
                'type'        => $data['type'] ?? 'sms',
                'message'     => $data['message'],
                'destination' => $dests[0],
                'from-number' => $data['from-number'],
            ] + $mms);
            if ($status >= 200 && $status < 300) {
                DataChanged::send($s['domain'], $s['user'], 'sessions', 'message-sent', null, ['remote' => $dests[0]]);
                \App\Services\OnboardingService::markAgentStep($s, 'first_send');
                SentMessageLog::record([
                    'tenant_id' => SentMessageLog::scopeTenant($s),
                    'domain' => $s['domain'], 'user' => $s['user'],
                    'agent_id' => ($s['role'] ?? null) === 'agent' ? ($s['agent_id'] ?? null) : null,
                    'actor_name' => $s['display_name'] ?? $s['username'] ?? null,
                    'category' => SentMessageLog::NEW_SMS,
                    'session_id' => is_array($body) ? ($body['messagesession-id'] ?? $body['messagesession_id'] ?? null) : null,
                    'from_number' => (string) $data['from-number'],
                    'to_number' => $dests[0],
                    'type' => $data['type'] ?? 'sms',
                ]);
            }
            $first = is_array($body) && isset($body[0]) ? $body[0] : (is_array($body) ? $body : []);
            return response()->json([
                'mode'              => 'single',
                'status'            => $status,
                'response'          => $body,
                'messagesession-id' => $first['messagesession-id'] ?? null,
                'destinations'      => $dests,
                'skipped'           => $skipped,
            ], $status);
        }

        // Multiple destinations → ONE call with a destination array on a fresh session.
        $sessionId = DynalinkService::randomSessionId();
        [$status, $body] = $this->dynalink->sendInSession(
            $this->dtoken(request()), $s['domain'], $s['user'], $sessionId,
            [
                'type'        => $data['type'] ?? 'sms',
                'message'     => $data['message'],
                'from-number' => $data['from-number'],
                'destination' => $dests,
            ] + $mms
        );

        if ($status >= 200 && $status < 300) {
            DataChanged::send($s['domain'], $s['user'], 'sessions', 'message-sent', null, ['remotes' => $dests]);
            \App\Services\OnboardingService::markAgentStep($s, 'first_send');
            foreach ($dests as $d) {
                SentMessageLog::record([
                    'tenant_id' => SentMessageLog::scopeTenant($s),
                    'domain' => $s['domain'], 'user' => $s['user'],
                    'agent_id' => ($s['role'] ?? null) === 'agent' ? ($s['agent_id'] ?? null) : null,
                    'actor_name' => $s['display_name'] ?? $s['username'] ?? null,
                    'category' => SentMessageLog::NEW_SMS,
                    'session_id' => $sessionId,
                    'from_number' => (string) $data['from-number'],
                    'to_number' => $d,
                    'type' => $data['type'] ?? 'sms',
                ]);
            }
        }
        return response()->json([
            'mode'              => 'group',
            'status'            => $status,
            'response'          => $body,
            'messagesession-id' => $sessionId,
            'destinations'      => $dests,
            'skipped'           => $skipped,
        ], $status);
    }
}
