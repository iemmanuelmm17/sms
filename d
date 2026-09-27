---
 .../Http/Controllers/SmsNumberController.php  | 87 ++++++++++++++++++-
 frontend/src/context/ReferenceDataContext.jsx | 43 +++++++--
 frontend/src/context/SocketContext.jsx        | 30 +++----
 frontend/src/pages/Contacts.jsx               |  3 +-
 frontend/src/pages/Messages.jsx               | 26 ++++--
 frontend/src/pages/Numbers.jsx                | 10 ++-
 frontend/src/pages/Users.jsx                  |  6 ++
 7 files changed, 168 insertions(+), 37 deletions(-)

diff --git a/backend/app/Http/Controllers/SmsNumberController.php b/backend/app/Http/Controllers/SmsNumberController.php
index bd16fae..d051d64 100644
--- a/backend/app/Http/Controllers/SmsNumberController.php
+++ b/backend/app/Http/Controllers/SmsNumberController.php
@@ -2,6 +2,8 @@
 
 namespace App\Http\Controllers;
 
+use App\Services\AgentAccess;
+use App\Services\CompanySettingsService;
 use App\Services\DynalinkService;
 use App\Http\Controllers\Concerns\ResolvesActor;
 use Illuminate\Http\Request;
@@ -9,13 +11,94 @@
 class SmsNumberController extends Controller
 {
     use ResolvesActor;
-    public function __construct(protected DynalinkService $dynalink) {}
+    public function __construct(
+        protected DynalinkService $dynalink,
+        protected AgentAccess $access,
+        protected CompanySettingsService $settings,
+    ) {}
 
     /** GET /api/sms-numbers — numbers assigned to this user (from-number choices). */
     public function index(Request $request)
     {
         $a = $this->actor($request);
-        return response()->json($this->dynalink->smsNumbers($this->dtoken(request()), $a['domain'], $a['user']));
+
+        // Base provider list (own numbers for the calling identity).
+        $ownRows = $this->dynalink->smsNumbers($this->dtoken($request), $a['domain'], $a['user']);
+
+        // Portal-authenticated agents: include granted SHARED numbers (view/reply/create)
+        // so dropdowns for reply AND create-new actually show the lines they were
+        // granted. Without this, api.smsNumbers() only returns the provider's
+        // own-number list and shared lines are invisible in the UI.
+        if (($a['role'] ?? '') === 'agent' && !empty($a['portal_auth'])) {
+            try {
+                $ext = $a['ext'] ?? $a['user'] ?? '';
+                $readable = $this->access->readableNumbers($a['domain'], $ext, $this->dtoken($request));
+                if (!empty($readable)) {
+                    $domainRows = $this->dynalink->domainSmsNumbers($this->dtoken($request), $a['domain']);
+                    $byDigits = [];
+                    foreach ($domainRows as $row) {
+                        if (!is_array($row)) continue;
+                        $n = DynalinkService::shapeNumber($row);
+                        if ($n['digits'] !== '') $byDigits[$n['digits']] = $n;
+                    }
+                    $seen = [];
+                    $out = [];
+                    foreach ($ownRows as $row) {
+                        if (!is_array($row)) continue;
+                        $n = DynalinkService::shapeNumber($row);
+                        if ($n['digits'] === '') continue;
+                        $seen[$n['digits']] = true;
+                        $out[] = $byDigits[$n['digits']] ?? $n;
+                    }
+                    foreach ($readable as $d) {
+                        if (isset($seen[$d])) continue;
+                        $seen[$d] = true;
+                        $out[] = $byDigits[$d] ?? [
+                            'number' => $d,
+                            'digits' => $d,
+                            'dest' => null,
+                            'mms_capable' => false,
+                            'group_mms_capable' => false,
+                            'application' => null,
+                            'carrier' => null,
+                            'domain' => $a['domain'],
+                        ];
+                    }
+                    usort($out, fn($x, $y) => strcmp($x['digits'] ?? '', $y['digits'] ?? ''));
+                    return response()->json($out);
+                }
+            } catch (\Throwable $e) {
+                // Fall back to provider list — never break the dropdown.
+                \Illuminate\Support\Facades\Log::warning('smsNumbers: readable merge failed', ['error' => $e->getMessage()]);
+            }
+        }
+
+        // Legacy local agents (pre-portal): return what they have assigned.
+        if (($a['role'] ?? '') === 'agent' && !empty($a['agent_id'])) {
+            try {
+                $agent = \App\Models\Agent::find($a['agent_id']);
+                if ($agent) {
+                    $assigned = $agent->assignedNumbers();
+                    if (!empty($assigned)) {
+                        $byDigits = [];
+                        foreach ($ownRows as $row) {
+                            if (!is_array($row)) continue;
+                            $n = DynalinkService::shapeNumber($row);
+                            if ($n['digits'] !== '') $byDigits[$n['digits']] = $n;
+                        }
+                        $out = [];
+                        foreach ($assigned as $d) {
+                            if (isset($byDigits[$d])) $out[] = $byDigits[$d];
+                            else $out[] = ['number' => $d, 'digits' => $d, 'dest' => null, 'mms_capable' => false, 'group_mms_capable' => false];
+                        }
+                        usort($out, fn($x,$y)=>strcmp($x['digits'] ?? '', $y['digits'] ?? ''));
+                        return response()->json($out);
+                    }
+                }
+            } catch (\Throwable $e) {}
+        }
+
+        return response()->json($ownRows);
     }
 
     /**
diff --git a/frontend/src/context/ReferenceDataContext.jsx b/frontend/src/context/ReferenceDataContext.jsx
index 8f44247..00aaba0 100644
--- a/frontend/src/context/ReferenceDataContext.jsx
+++ b/frontend/src/context/ReferenceDataContext.jsx
@@ -22,8 +22,9 @@ import { useSocket } from './SocketContext';
 const Ctx = createContext(null);
 
 const EMPTY = {
-  contacts: [], templates: [], agents: [], numbers: [], companies: [],
+  contacts: [], templates: [], agents: [], numbers: [], companies: [], groups: [],
   optOuts: [], optStates: {}, meta: {}, settings: null,
+  scheduled: [], autoReplies: [], emailSenders: [], subscriptions: [],
 };
 
 export function ReferenceDataProvider({ children }) {
@@ -45,16 +46,21 @@ export function ReferenceDataProvider({ children }) {
     numbers: () => (isAgent ? api.smsNumbers() : api.domainSmsNumbers())
       .then((v) => set({ numbers: Array.isArray(v) ? v : (v?.numbers || []) })),
     companies: () => api.companies().then((v) => set({ companies: Array.isArray(v) ? v : [] })),
+    groups: () => api.groups().then((v) => set({ groups: Array.isArray(v) ? v : [] })),
     optOuts: () => api.optOuts().then((v) => set({ optOuts: Array.isArray(v) ? v : [] })),
     settings: () => api.companySettings().then((v) => set({ settings: v || {} })),
     optStates: () => api.optEvents().then((rows) => {
       const m = {};
       (rows || []).forEach((r) => {
-        const d = String(r.phone_number ?? '').replace(/\D/g, '');
+        const d = String(r.phone_number ?? '').replace(/\\D/g, '');
         if (d) m[d] = r.direction;
       });
       set({ optStates: m });
     }),
+    scheduled: () => api.scheduled().then((v) => set({ scheduled: Array.isArray(v) ? v : [] })),
+    autoReplies: () => api.autoReplies().then((v) => set({ autoReplies: Array.isArray(v) ? v : [] })),
+    emailSenders: () => api.emailSmsSenders().then((v) => set({ emailSenders: Array.isArray(v) ? v : [] })),
+    subscriptions: () => api.subscriptions().then((v) => set({ subscriptions: Array.isArray(v) ? v : (v?.subscriptions || v || []) })),
   }), [isAgent, set]);
 
   /** Refresh one slice by name; never throws. */
@@ -78,17 +84,28 @@ export function ReferenceDataProvider({ children }) {
   }, [user, refreshAll]);
 
   // Targeted invalidation — only the slice the event can affect.
+  // Covers every DataChanged resource the backend emits so users on the same
+  // domain see each other's mutations instantly without a manual refresh.
   useEffect(() => {
     if (!user || !lastSync?.resource) return;
     const map = {
       agents: ['agents'],
       contacts: ['contacts'],
-      'auto-replies': [],
+      groups: ['groups'],
+      companies: ['companies'],
       templates: ['templates'],
-      'convo-meta': ['meta'],
-      'company-settings': ['settings', 'numbers'],
+      scheduled: ['scheduled'],
+      'auto-replies': ['autoReplies'],
+      'email-sms-senders': ['emailSenders', 'settings'],
+      subscriptions: ['subscriptions'],
+      'company-settings': ['settings', 'numbers', 'emailSenders'],
       numbers: ['numbers', 'settings'],
       optouts: ['optOuts', 'optStates'],
+      'opt-events': ['optStates', 'optOuts'],
+      'convo-meta': ['meta'],
+      // sessions are owned by Messages.jsx itself, but we refresh meta
+      // (queue status lives there) so the shared queue badge updates.
+      sessions: ['meta'],
     };
     const keys = map[lastSync.resource];
     if (keys && keys.length) refresh(...keys);
@@ -97,18 +114,30 @@ export function ReferenceDataProvider({ children }) {
   // Same-tab nudges from components that mutate these directly.
   useEffect(() => {
     const onContacts = () => refresh('contacts');
-    const onCompanies = () => refresh('companies');
+    const onCompanies = () => refresh('companies', 'groups');
     const onMeta = () => refresh('meta');
-    const onShared = () => refresh('settings');
+    const onShared = () => refresh('settings', 'numbers');
+    const onGroups = () => refresh('groups');
+    const onTemplates = () => refresh('templates');
+    const onScheduled = () => refresh('scheduled');
+    const onAutoReplies = () => refresh('autoReplies');
     window.addEventListener('contacts-changed', onContacts);
     window.addEventListener('companies-changed', onCompanies);
     window.addEventListener('convo-meta-changed', onMeta);
     window.addEventListener('shared-numbers-changed', onShared);
+    window.addEventListener('groups-changed', onGroups);
+    window.addEventListener('templates-changed', onTemplates);
+    window.addEventListener('scheduled-changed', onScheduled);
+    window.addEventListener('auto-replies-changed', onAutoReplies);
     return () => {
       window.removeEventListener('contacts-changed', onContacts);
       window.removeEventListener('companies-changed', onCompanies);
       window.removeEventListener('convo-meta-changed', onMeta);
       window.removeEventListener('shared-numbers-changed', onShared);
+      window.removeEventListener('groups-changed', onGroups);
+      window.removeEventListener('templates-changed', onTemplates);
+      window.removeEventListener('scheduled-changed', onScheduled);
+      window.removeEventListener('auto-replies-changed', onAutoReplies);
     };
   }, [refresh]);
 
diff --git a/frontend/src/context/SocketContext.jsx b/frontend/src/context/SocketContext.jsx
index 4318aef..cb8e152 100644
--- a/frontend/src/context/SocketContext.jsx
+++ b/frontend/src/context/SocketContext.jsx
@@ -33,9 +33,6 @@ export function SocketProvider({ children }) {
         ]);
         window.Pusher = Pusher;
 
-        // Fallback to localhost:8000 if VITE_API_BASE_URL is not set in your .env
-        const backendUrl = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000';
-
         // Realtime connection target: Super → Settings (saved server-side,
         // served by /api/realtime) wins; the build-time .env values are the
         // per-field fallback. Fails soft — offline/demo keeps the .env values.
@@ -54,10 +51,11 @@ export function SocketProvider({ children }) {
           wssPort: rtPort,
           forceTLS: rtScheme === 'https',
           enabledTransports: ['ws', 'wss'],
-          
-          // Absolute path to the Laravel API server to prevent Vite port routing leaks
-          authEndpoint: `${backendUrl}/broadcasting/auth`,
-          
+
+          // Relative auth endpoint — works behind any proxy/preview host and
+          // ensures the session cookie is sent (see authorizer below).
+          authEndpoint: '/broadcasting/auth',
+
           // Intercept authorization using standard axios to force session inclusion
           authorizer: (channel, options) => {
             return {
@@ -76,22 +74,18 @@ export function SocketProvider({ children }) {
         });
 
         echoRef.current = echo;
-        
+
         // Same sanitization as the backend (ChannelName): Dynalink domains
         // contain dots ("1180.DynaCloud") but Laravel channel params can't
         // match dots — unsanitized names fail auth with 403.
         const safe = (v) => String(v).replace(/[^A-Za-z0-9-]/g, '_');
-        
+
         // ONE shared room per Dynalink domain: private-sms.{domain}.shared.
-        // The user/extension is deliberately NOT in the channel name — the
-        // domain's admin and every agent (each on a different user
-        // extension) all join the same room, and the backend sends every
-        // broadcast (mutations AND inbound SMS) to that same room. The
-        // payload's scope_user carries the token (kept for future room
-        // scheme changes); 'shared' is the historical agent-room token.
-        const scopeUser = user.scope_user || 'shared';
-		const chName = `sms.${safe(user.domain)}.${safe(scopeUser)}`;
-		const ch = echo.private(chName);
+        // Always subscribe to the shared room — the backend broadcasts every
+        // DataChanged and inbound SMS there so all users on the same domain
+        // see changes instantly without refresh, regardless of extension.
+        const chName = `sms.${safe(user.domain)}.shared`;
+        const ch = echo.private(chName);
         
         ch.listen('.sms.incoming', (e) => {
 		  // 1. Force a clean, brand-new object reference with a distinct timestamp
diff --git a/frontend/src/pages/Contacts.jsx b/frontend/src/pages/Contacts.jsx
index 19fe22e..815897e 100644
--- a/frontend/src/pages/Contacts.jsx
+++ b/frontend/src/pages/Contacts.jsx
@@ -51,11 +51,12 @@ export default function Contacts() {
     return () => window.removeEventListener('contacts-changed', onChanged);
   }, []);
 
-  // Another instance changed contacts / companies → refresh.
+  // Another instance changed contacts / companies / groups → refresh.
   useEffect(() => {
     if (!lastSync) return;
     if (lastSync.resource === 'contacts') reload();
     if (lastSync.resource === 'companies') api.companies().then(setCompanies).catch(() => {});
+    if (lastSync.resource === 'groups') reload();
     // eslint-disable-next-line react-hooks/exhaustive-deps
   }, [lastSync]);
 
diff --git a/frontend/src/pages/Messages.jsx b/frontend/src/pages/Messages.jsx
index 6c64924..89c97d5 100644
--- a/frontend/src/pages/Messages.jsx
+++ b/frontend/src/pages/Messages.jsx
@@ -917,14 +917,14 @@ export default function Messages() {
   })();
   useEffect(() => {
     if (user?.role !== 'agent') return;
-    const opts = numbers.filter((n) => agentAllowed.includes(digits(n.number)));
+    const opts = agentAllowedOpts;
     if (!opts.length) { if (fromNumber) setFromNumber(''); return; }
     if (!opts.some((n) => String(n.number) === fromNumber)) {
       const dd = digits(user?.default_number);
       const pick = opts.find((n) => digits(n.number) === dd) || opts[0];
       setFromNumber(String(pick.number));
     }
-  }, [user, numbers]);
+  }, [user, numbers, agentAllowedOpts.length]);
 
   /**
    * Smart sender: reply from the number that RECEIVED the message.
@@ -1968,12 +1968,22 @@ function NewMessageModal({ contacts, numbers, defaultFrom, templates, contactByP
   const allowed = isAgent
     ? ((user?.creatable_numbers || user?.assigned_numbers || [])).map(digits) : [];
   const agentDefault = isAgent ? String(user?.default_number || allowed[0] || '') : '';
+  // Agent dropdown must show granted SHARED numbers even when the provider's
+  // smsNumbers list doesn't contain them (shared lines are owned by another
+  // extension). Synthesize a row for anything permitted but missing.
+  const sendOpts = (() => {
+    if (!isAgent) return numbers;
+    const mine = numbers.filter((n) => allowed.includes(digits(n.number)));
+    const have = new Set(mine.map((n) => digits(n.number)));
+    const extra = allowed.filter((d) => d && !have.has(d)).map((d) => ({ number: d }));
+    return [...mine, ...extra];
+  })();
   const [from, setFrom] = useState(isAgent ? agentDefault : (defaultFrom || (numbers[0] ? String(numbers[0].number) : '')));
   // Numbers may still be loading when the dialog opens from another page —
   // adopt the default sender as soon as the list arrives.
   useEffect(() => {
     if (isAgent) {
-      const opts = numbers.filter((n) => allowed.includes(digits(n.number)));
+      const opts = sendOpts;
       if (!opts.length) { if (from) setFrom(''); return; }
       if (!opts.some((n) => String(n.number) === from)) {
         const dd = digits(agentDefault);
@@ -1983,7 +1993,7 @@ function NewMessageModal({ contacts, numbers, defaultFrom, templates, contactByP
       return;
     }
     if (!from && numbers.length) setFrom(defaultFrom || String(numbers[0].number));
-  }, [numbers, defaultFrom, isAgent, agentDefault, allowed.length]);
+  }, [numbers, defaultFrom, isAgent, agentDefault, allowed.length, sendOpts.length]);
   const [busy, setBusy] = useState(false);
   const [filter, setFilter] = useState('');
   const [attach, setAttach] = useState(null);
@@ -2107,12 +2117,12 @@ function NewMessageModal({ contacts, numbers, defaultFrom, templates, contactByP
       </div>
       <label className="text-xs font-medium text-slate-600">From</label>
       {isAgent ? (
-        allowed.length > 1 ? (
+        sendOpts.length > 1 ? (
           <select value={from} onChange={(e) => setFrom(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mb-3 mt-1">
-            {numbers.filter((n) => allowed.includes(digits(n.number))).map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
+            {sendOpts.map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
           </select>
-        ) : allowed.length === 1 ? (
-          <div className="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-700 mb-3 mt-1">📱 {fmtPhone(allowed[0])} <span className="text-[11px] text-slate-400">(your assigned number)</span></div>
+        ) : sendOpts.length === 1 ? (
+          <div className="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-700 mb-3 mt-1">📱 {fmtPhone(sendOpts[0].number)} <span className="text-[11px] text-slate-400">(your assigned number)</span></div>
         ) : (
           <div className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2.5 mb-3 mt-1">⚠️ No SMS number assigned to your account — ask your admin to set one.</div>
         )
diff --git a/frontend/src/pages/Numbers.jsx b/frontend/src/pages/Numbers.jsx
index f95bee4..8fc33d5 100644
--- a/frontend/src/pages/Numbers.jsx
+++ b/frontend/src/pages/Numbers.jsx
@@ -167,10 +167,18 @@ export default function Numbers() {
 
   const digitsOf = (n) => digits(n?.number);
   // Agents see the numbers they have VIEW on (own + view grants).
+  // Synthesize a row for any granted shared line missing from the provider list
+  // (owned by another extension) so the read-only view still shows it.
   const allowedNums = isAgent
     ? new Set((user?.readable_numbers || user?.assigned_numbers || []).map(digits).filter((d) => d.length >= 7))
     : null;
-  const visibleNumbers = isAgent ? numbers.filter((n) => allowedNums.has(digitsOf(n))) : numbers;
+  const visibleNumbers = (() => {
+    if (!isAgent) return numbers;
+    const mine = numbers.filter((n) => allowedNums.has(digitsOf(n)));
+    const have = new Set(mine.map((n) => digitsOf(n)));
+    const extra = [...allowedNums].filter((d) => d && !have.has(d)).map((d) => ({ number: d }));
+    return [...mine, ...extra];
+  })();
   const notifyFor = (d) => (numEmail[d]?.notify || []).join('; ');
   const rowsFor = (d) => senders.filter((r) => (r.numbers || []).map(String).includes(d));
   const sendFor = (d) => rowsFor(d).map((r) => r.email).join('; ');
diff --git a/frontend/src/pages/Users.jsx b/frontend/src/pages/Users.jsx
index 2571a2c..9993f48 100644
--- a/frontend/src/pages/Users.jsx
+++ b/frontend/src/pages/Users.jsx
@@ -2,6 +2,7 @@ import { useEffect, useMemo, useState } from 'react';
 import { api, fmtPhone, initials } from '../api/client';
 import { toastError, toastSuccess } from '../lib/toast';
 import { useAuth } from '../context/AuthContext';
+import { useSocket } from '../context/SocketContext';
 
 import { useOnboarding } from '../components/onboarding/useOnboarding';
 const digits = (v) => String(v ?? '').replace(/\D/g, '');
@@ -98,7 +99,12 @@ export default function Users() {
       setLoading(false);
     }
   };
+  const { lastSync } = useSocket();
   useEffect(() => { if (isAdmin) load(); else setLoading(false); }, [isAdmin]);
+  useEffect(() => {
+    if (!lastSync) return;
+    if (lastSync.resource === 'agents' || lastSync.resource === 'company-settings') load();
+  }, [lastSync]);
 
   const labelFor = (d) => meta[d]?.label || '';
   const numById = useMemo(() => {
-- 
2.39.5


