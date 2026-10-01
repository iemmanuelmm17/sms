<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Models\EmailSmsSender;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/**
 * Per-domain authorized email→SMS senders. Every user on the domain may
 * manage them (tenant decision 2026-09-30): the addresses govern who may
 * send SMS by email and reply to SMS notification emails, per number.
 */
class EmailSmsSenderController extends Controller
{
    use ResolvesActor;

    /** GET /api/email-sms-senders */
    public function index(Request $request)
    {
        $a = $this->actor($request);
        return response()->json(EmailSmsSender::where('domain', $a['domain'])
            ->orderBy('email')->get(['id', 'email', 'numbers', 'default_number']));
    }

    /** POST /api/email-sms-senders { email, numbers[], default_number? } */
    public function store(Request $request)
    {
        $a = $this->actor($request);
        $data = $request->validate([
            'email' => 'required|email|max:190',
            'numbers' => 'required|array|min:1|max:20',
            'numbers.*' => 'required|string|max:25',
            'default_number' => 'nullable|string|max:25',
        ]);
        $numbers = $this->digits($data['numbers']);
        if ($numbers === []) {
            return response()->json(['message' => 'Assign at least one valid SMS number (7-15 digits).'], 422);
        }
        $default = $this->one($data['default_number'] ?? '');
        if ($default !== '' && !in_array($default, $numbers, true)) {
            return response()->json(['message' => 'Default number must be one of the assigned numbers.'], 422);
        }
        // Auto-first: no explicit default → the first assigned number.
        if ($default === '') $default = $numbers[0];
        $email = strtolower(trim($data['email']));
        if (EmailSmsSender::where('domain', $a['domain'])->where('email', $email)->exists()) {
            return response()->json(['message' => 'That email is already authorized.'], 422);
        }
        $sender = EmailSmsSender::create(['domain' => $a['domain'], 'email' => $email,
            'numbers' => $numbers, 'default_number' => $default]);
        $this->audit($request, 'email-sms-sender.created',
            ['email' => $email, 'numbers' => $numbers, 'default_number' => $default]);
        DataChanged::send($a['domain'], $a['user'], 'email-sms-senders', 'saved', $sender->id);
        return response()->json($sender, 201);
    }

    /**
     * PUT /api/email-sms-senders/{id} { numbers[]?, default_number? }
     * (email is immutable). An omitted/blank default keeps the stored
     * one when still assigned, else falls back to the first number.
     */
    public function update(Request $request, int $id)
    {
        $a = $this->actor($request);
        $sender = EmailSmsSender::where('domain', $a['domain'])->findOrFail($id);
        $data = $request->validate([
            'numbers' => 'sometimes|array|min:1|max:20',
            'numbers.*' => 'required|string|max:25',
            'default_number' => 'nullable|string|max:25',
        ]);
        if (!array_key_exists('numbers', $data) && !array_key_exists('default_number', $data)) {
            return response()->json(['message' => 'Nothing to update.'], 422);
        }
        $numbers = array_key_exists('numbers', $data) ? $this->digits($data['numbers']) : $sender->digits();
        if ($numbers === []) {
            return response()->json(['message' => 'Assign at least one valid SMS number (7-15 digits).'], 422);
        }
        $wantDefault = array_key_exists('default_number', $data) ? $this->one($data['default_number'] ?? '') : null;
        if ($wantDefault !== null && $wantDefault !== '' && !in_array($wantDefault, $numbers, true)) {
            return response()->json(['message' => 'Default number must be one of the assigned numbers.'], 422);
        }
        $default = ($wantDefault !== null && $wantDefault !== '')
            ? $wantDefault
            : $this->one((string) $sender->default_number);
        if (!in_array($default, $numbers, true)) $default = $numbers[0];
        $sender->update(['numbers' => $numbers, 'default_number' => $default]);
        $this->audit($request, 'email-sms-sender.updated',
            ['email' => $sender->email, 'numbers' => $numbers, 'default_number' => $default]);
        DataChanged::send($a['domain'], $a['user'], 'email-sms-senders', 'saved', $sender->id);
        return response()->json($sender->fresh());
    }

    /** DELETE /api/email-sms-senders/{id} */
    public function destroy(Request $request, int $id)
    {
        $a = $this->actor($request);
        $sender = EmailSmsSender::where('domain', $a['domain'])->findOrFail($id);
        $sender->delete();
        $this->audit($request, 'email-sms-sender.deleted', ['email' => $sender->email]);
        DataChanged::send($a['domain'], $a['user'], 'email-sms-senders', 'deleted', $id);
        return response()->json(['ok' => true]);
    }

    /**
     * PUT /api/number-emails/{number} { notify: [], enabled? }
     *
     * The per-number "notify on incoming SMS/MMS" list — the RECEIVING side
     * of the email gateway. Admins manage every number; other users manage
     * the numbers they own or that are shared with them (the same visibility
     * the Numbers page shows). Stores into the same company-settings slot
     * the admin endpoint writes, so the mailer keeps ONE source of truth.
     */
    public function saveNotifyEmails(Request $request, string $number)
    {
        $a = $this->actor($request);
        $d = preg_replace('/\D/', '', (string) $number);
        if (strlen($d) < 7 || strlen($d) > 15) {
            return response()->json(['message' => 'Invalid SMS number.'], 422);
        }
        // Admins pass; agents need the number to be their own or shared.
        $this->assertAgentNumber($request, $d, 'read');
        $data = $request->validate([
            'notify'   => 'present|array|max:10',
            'notify.*' => 'email|max:190',
            'enabled'  => 'sometimes|boolean',
        ]);
        $settings = app(\App\Services\CompanySettingsService::class);
        $prev = (array) ($settings->get($a['domain'])['number_email'][$d] ?? []);
        $enabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : (bool) ($prev['enabled'] ?? true);
        $notify = array_values(array_unique(array_map(
            fn($e) => mb_strtolower(trim((string) $e)), (array) $data['notify']
        ), SORT_REGULAR));
        $settings->setNumberEmail($a['domain'], $d, $notify, $enabled);
        $this->audit($request, 'number.notify-emails-changed', [
            'number' => $d, 'count' => count($notify), 'enabled' => $enabled,
        ]);
        DataChanged::send($a['domain'], $a['user'], 'company-settings', 'saved');
        return response()->json(['ok' => true, 'notify' => $notify, 'enabled' => $enabled]);
    }

    /** Normalize to unique digit strings, 7-15 digits each. */
    protected function digits(array $numbers): array
    {
        $out = [];
        foreach ($numbers as $n) {
            $d = preg_replace('/\D/', '', (string) $n);
            if (strlen($d) >= 7 && strlen($d) <= 15) $out[] = $d;
        }
        return array_values(array_unique($out));
    }

    /** Normalize one number to digits ('' when unusable). */
    protected function one(mixed $v): string
    {
        $d = preg_replace('/\D/', '', (string) $v);
        return (strlen($d) >= 7 && strlen($d) <= 15) ? $d : '';
    }
}
