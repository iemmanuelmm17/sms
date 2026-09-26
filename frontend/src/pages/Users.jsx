import { useEffect, useMemo, useState } from 'react';
import { api, fmtPhone, initials } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import { useAuth } from '../context/AuthContext';

import { useOnboarding } from '../components/onboarding/useOnboarding';
const digits = (v) => String(v ?? '').replace(/\D/g, '');

function ago(iso) {
  if (!iso) return 'never';
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 90) return 'just now';
  const m = s / 60;
  if (m < 60) return `${Math.round(m)}m ago`;
  const h = m / 60;
  if (h < 24) return `${Math.round(h)}h ago`;
  return `${Math.round(h / 24)}d ago`;
}

const STATUS = {
  active:   ['Active', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
  disabled: ['Disabled', 'bg-red-50 text-red-700 border-red-200'],
  pending:  ['Never signed in', 'bg-amber-50 text-amber-700 border-amber-200'],
};

/**
 * Users — the roster of portal-authenticated agents.
 *
 * Deliberately has no "add agent" or password controls: the Dynalink portal
 * owns authentication. Agents appear here automatically on first sign-in.
 * Admins control two things only — the active/disabled kill switch, and which
 * SHARED numbers each extension may read and reply on.
 */
export default function Users() {
  const { user } = useAuth();
  const { markStep } = useOnboarding();
  const isAdmin = user?.role !== 'agent';

  const [rows, setRows] = useState([]);
  const [shared, setShared] = useState([]);
  const [numbers, setNumbers] = useState([]);   // domain inventory, for labels
  const [meta, setMeta] = useState({});
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(null);       // ext being edited
  const [draft, setDraft] = useState([]);       // granted digits, mid-edit
  const [busy, setBusy] = useState('');

  const load = async () => {
    try {
      const [d, n, c] = await Promise.all([
        api.agentIdentities(),
        api.domainSmsNumbers().catch(() => ({ numbers: [] })),
        api.companySettings().catch(() => ({})),
      ]);
      setRows(Array.isArray(d?.agents) ? d.agents : []);
      setShared((d?.shared || []).map(digits));
      setNumbers(Array.isArray(n?.numbers) ? n.numbers : []);
      setMeta(c?.number_meta || {});
      setErr('');
      // The old "invite an agent" step is unreachable now that users sign in
      // via the portal, so reviewing the roster is what completes it.
      markStep('agent_created');
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not load the agent roster.');
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { if (isAdmin) load(); else setLoading(false); }, [isAdmin]);

  const labelFor = (d) => meta[d]?.label || '';
  const numById = useMemo(() => {
    const m = {};
    for (const n of numbers) m[digits(n.number)] = n;
    return m;
  }, [numbers]);

  const describe = (d) => {
    const n = numById[d];
    const bits = [labelFor(d), n?.dest ? `Ext ${n.dest}` : null].filter(Boolean);
    return bits.join(' • ');
  };

  const visible = useMemo(() => {
    const t = q.trim().toLowerCase();
    if (!t) return rows;
    const nq = t.replace(/\D/g, '');
    return rows.filter((a) =>
      a.ext.toLowerCase().includes(t) ||
      String(a.display_name || '').toLowerCase().includes(t) ||
      String(a.email || '').toLowerCase().includes(t) ||
      (nq && [...(a.own_numbers || []), ...(a.granted || [])].some((d) => d.includes(nq))));
  }, [rows, q]);

  const startEdit = (a) => {
    setOpen(a.ext);
    setDraft([...(a.granted || [])].map(digits));
  };
  const toggleGrant = (d) =>
    setDraft((p) => (p.includes(d) ? p.filter((x) => x !== d) : [...p, d]));

  const saveGrants = async (a) => {
    setBusy(`g-${a.ext}`);
    try {
      await api.setAgentGrants(a.ext, draft);
      toastSuccess(`Updated shared access for ${a.display_name || a.ext}.`);
      setOpen(null);
      await load();
    } catch (e) {
      toastError(e?.response?.data?.message || 'Could not save access.');
    } finally { setBusy(''); }
  };

  /**
   * Refresh one user's name/email from the portal.
   *
   * Lazy by design: one request per row, patching only that row in place, so
   * the roster stays usable and a slow portal can't block the whole page.
   */
  const resync = async (a) => {
    setBusy(`r-${a.ext}`);
    try {
      const r = await api.resyncAgent(a.ext);
      if (r?.agent) {
        setRows((p) => p.map((x) => (x.ext === a.ext ? { ...x, ...r.agent } : x)));
        toastSuccess(r.changed
          ? `Updated ${r.agent.display_name || a.ext} from the portal.`
          : 'Already up to date.');
      }
    } catch (e) {
      toastError(e?.response?.data?.message || 'Could not reach the portal.');
    } finally { setBusy(''); }
  };

  const resyncAll = async () => {
    const list = visible.filter((a) => a.status !== 'pending');
    if (!list.length) return;
    setBusy('r-all');
    let ok = 0;
    // Sequential on purpose: each is a provider round trip, and firing 150 at
    // once would hammer the portal and risk rate limiting.
    for (const a of list) {
      try {
        const r = await api.resyncAgent(a.ext);
        if (r?.agent) {
          ok += 1;
          setRows((p) => p.map((x) => (x.ext === a.ext ? { ...x, ...r.agent } : x)));
        }
      } catch { /* keep going: one failure shouldn't abort the rest */ }
    }
    setBusy('');
    toastSuccess(`Synced ${ok} of ${list.length} user${list.length === 1 ? '' : 's'}.`);
  };

  const unlock = async (a) => {
    setBusy(`u-${a.ext}`);
    try {
      await api.unlockAgent(a.ext);
      setRows((p) => p.map((x) => (x.ext === a.ext ? { ...x, locked_secs: 0 } : x)));
      toastSuccess(`${a.display_name || a.ext} can sign in again.`);
    } catch (e) {
      toastError(e?.response?.data?.message || 'Could not unlock this user.');
    } finally { setBusy(''); }
  };

  const flipStatus = async (a) => {
    const next = a.status === 'disabled' ? 'active' : 'disabled';
    if (next === 'disabled' && !window.confirm(
      `Disable ${a.display_name || a.ext}?\n\nThey will be signed out and cannot access any inbox until re-enabled.`)) return;
    setBusy(`s-${a.ext}`);
    try {
      await api.setAgentStatus(a.ext, next);
      toastSuccess(next === 'disabled' ? 'Agent disabled.' : 'Agent re-enabled.');
      await load();
    } catch (e) {
      toastError(e?.response?.data?.message || 'Could not change status.');
    } finally { setBusy(''); }
  };

  if (!isAdmin) {
    return (
      <div className="p-4 md:p-6">
        <h1 className="text-xl font-bold text-slate-900 mb-3">Users</h1>
        <div className="bg-white border rounded-xl p-6 text-center text-sm text-slate-500">
          This page is only available to admins.
        </div>
      </div>
    );
  }

  return (
    <div className="p-4 md:p-6 space-y-4 max-w-4xl">
      <div className="flex items-start gap-3 flex-wrap">
        <div className="flex-1 min-w-0">
          <h1 className="text-xl font-bold text-slate-900">Users</h1>
          <p className="text-xs text-slate-500 mt-0.5">
            Users sign in with their Dynalink portal login and appear here automatically.
            Their own numbers come from the portal; grant shared numbers below.
          </p>
        </div>
        {rows.length > 0 && (
          <div className="flex items-center gap-2 w-full sm:w-auto">
            <input
              value={q} onChange={(e) => setQ(e.target.value)}
              placeholder="🔍 Search name, extension, email, or number…"
              className="flex-1 sm:w-72 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
            />
            <button
              onClick={resyncAll} disabled={!!busy}
              title="Refresh every user's name and email from the Dynalink portal"
              className="text-xs font-semibold border rounded-lg px-3 py-2 hover:bg-slate-50 shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {busy === 'r-all' ? 'Syncing…' : '⟳ Sync all'}
            </button>
          </div>
        )}
      </div>

      {err && <div className="bg-red-50 border border-red-200 text-red-700 rounded-xl p-3 text-sm">{err}</div>}

      {loading && (
        <div className="space-y-2" aria-busy="true">
          {[0, 1, 2].map((i) => (
            <div key={i} className="bg-white border rounded-xl p-4 animate-pulse flex items-center gap-3">
              <div className="w-9 h-9 rounded-full bg-slate-200 shrink-0" />
              <div className="flex-1 space-y-2">
                <div className="h-3 bg-slate-200 rounded w-1/3" />
                <div className="h-2.5 bg-slate-100 rounded w-2/3" />
              </div>
            </div>
          ))}
        </div>
      )}

      {!loading && rows.length === 0 && !err && (
        <div className="bg-white border rounded-xl p-8 text-center">
          <div className="text-3xl mb-2" aria-hidden="true">👥</div>
          <p className="text-sm font-semibold text-slate-800">No users yet</p>
          <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
            Users appear here the first time they sign in with their Dynalink
            portal login — you don&apos;t need to create accounts for them.
          </p>
        </div>
      )}

      {!loading && visible.map((a) => {
        const isOpen = open === a.ext;
        const [label, cls] = STATUS[a.status] || STATUS.pending;
        const own = a.own_numbers || [];
        const granted = a.granted || [];
        const stale = a.granted_inactive || [];
        const dirty = isOpen &&
          JSON.stringify([...draft].sort()) !== JSON.stringify([...granted].sort());

        return (
          <div key={a.ext} className="bg-white border rounded-xl overflow-hidden">
            <div className="flex items-center gap-3 px-4 py-3 flex-wrap">
              <span
                className="w-9 h-9 rounded-full text-white text-xs font-bold flex items-center justify-center shrink-0"
                style={{ backgroundColor: a.tag_color || '#6366f1' }}
              >
                {initials(a.display_name || a.ext)}
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-sm font-semibold text-slate-900 truncate">
                    {a.display_name || a.ext}
                  </span>
                  <span className="text-[11px] text-slate-500">Ext {a.ext}</span>
                    <span className={`text-[10px] font-semibold rounded-full px-2 py-0.5 border ${cls}`}>{label}</span>
                  {a.locked_secs > 0 && (
                    <span className="text-[10px] font-semibold rounded-full px-2 py-0.5 border bg-amber-50 text-amber-700 border-amber-200">
                      Locked out
                    </span>
                  )}
                </div>
                <p className="text-[11px] text-slate-400 mt-0.5">
                  {a.email ? `${a.email} • ` : ''}{own.length} own • {granted.length} shared • last seen {ago(a.last_seen_at)}
                </p>
                {(a.department || a.site) && (
                  <p className="text-[11px] text-slate-400">
                    {[a.department, a.site].filter(Boolean).join(' • ')}
                  </p>
                )}
                {!a.first_name && a.status !== 'pending' && (
                  <p className="text-[11px] text-amber-700 mt-0.5">
                    ⓘ No name from the portal yet — signatures will show the extension. Sync to fix.
                  </p>
                )}
              </div>
              {a.locked_secs > 0 && (
                <button
                  onClick={() => unlock(a)} disabled={!!busy}
                  title={`Locked out after failed sign-ins — ${Math.ceil(a.locked_secs / 60)} min left`}
                  className="text-xs font-semibold rounded-lg px-2.5 py-1.5 shrink-0 bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-50"
                >
                  {busy === `u-${a.ext}` ? '…' : `🔓 Unlock (${Math.ceil(a.locked_secs / 60)}m)`}
                </button>
              )}
              <button
                onClick={() => resync(a)} disabled={!!busy}
                title={a.profile_synced_at
                  ? `Refresh from the portal (last synced ${ago(a.profile_synced_at)})`
                  : 'Pull this user\u2019s name and email from the portal'}
                className="text-xs font-semibold border rounded-lg px-2.5 py-1.5 hover:bg-slate-50 shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {busy === `r-${a.ext}` ? '…' : '⟳'}
              </button>
              <button
                onClick={() => (isOpen ? setOpen(null) : startEdit(a))}
                className="text-xs font-semibold border rounded-lg px-3 py-1.5 hover:bg-slate-50 shrink-0"
              >
                {isOpen ? 'Close' : 'Shared access'}
              </button>
              <button
                onClick={() => flipStatus(a)}
                disabled={a.status === 'pending' || busy === `s-${a.ext}`}
                title={a.status === 'pending' ? 'This agent has never signed in' : undefined}
                className={`text-xs font-semibold rounded-lg px-3 py-1.5 shrink-0 disabled:opacity-40 disabled:cursor-not-allowed ${
                  a.status === 'disabled'
                    ? 'bg-emerald-600 hover:bg-emerald-700 text-white'
                    : 'border text-red-600 hover:bg-red-50'}`}
              >
                {busy === `s-${a.ext}` ? '…' : a.status === 'disabled' ? 'Enable' : 'Disable'}
              </button>
            </div>

            {stale.length > 0 && (
              <p className="px-4 pb-2 -mt-1 text-[11px] text-amber-700">
                ⓘ {stale.length} granted number{stale.length === 1 ? ' is' : 's are'} no longer shared,
                so access is paused: {stale.map(fmtPhone).join(', ')}
              </p>
            )}

            {isOpen && (
              <div className="border-t px-4 py-3 bg-slate-50">
                <p className="text-xs font-semibold text-slate-700">Own numbers</p>
                <p className="text-[11px] text-slate-500 mb-1.5">
                  From the portal — assigned to extension {a.ext}. Always accessible; not editable here.
                </p>
                <div className="flex flex-wrap gap-1.5 mb-3">
                  {own.map((d) => (
                    <span key={d} className="text-[11px] bg-white border rounded-full px-2 py-1 text-slate-600">
                      {fmtPhone(d)}{describe(d) && <span className="text-slate-400"> · {describe(d)}</span>}
                    </span>
                  ))}
                  {own.length === 0 && (
                    <span className="text-[11px] text-slate-400">
                      No numbers assigned to this extension in the portal.
                    </span>
                  )}
                </div>

                <p className="text-xs font-semibold text-slate-700">Shared numbers</p>
                <p className="text-[11px] text-slate-500 mb-1.5">
                  Tick the shared lines this agent may read and reply on.
                  {shared.length === 0 && ' No numbers are marked shared yet — do that on the Numbers page first.'}
                </p>
                <div className="border rounded-lg bg-white divide-y max-h-56 overflow-y-auto">
                  {shared.map((d) => (
                    <label key={d} className="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-slate-50">
                      <input
                        type="checkbox" className="w-4 h-4 accent-brand-600 shrink-0"
                        checked={draft.includes(d)} onChange={() => toggleGrant(d)}
                      />
                      <span className="min-w-0 truncate">{fmtPhone(d)}</span>
                      {describe(d) && <span className="text-[11px] text-slate-400 truncate">{describe(d)}</span>}
                      {own.includes(d) && (
                        <span className="ml-auto text-[10px] text-slate-400 shrink-0">already theirs</span>
                      )}
                    </label>
                  ))}
                  {shared.length === 0 && (
                    <p className="px-3 py-3 text-[11px] text-slate-400">No shared numbers.</p>
                  )}
                </div>

                <div className="flex items-center gap-2 mt-3 flex-wrap">
                  <button
                    onClick={() => saveGrants(a)} disabled={!dirty || busy === `g-${a.ext}`}
                    className="text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4 py-2 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    {busy === `g-${a.ext}` ? 'Saving…' : dirty ? 'Save access' : 'Saved'}
                  </button>
                  {dirty && (
                    <>
                      <button onClick={() => setDraft([...granted])}
                        className="text-sm text-slate-500 hover:text-slate-700 font-medium px-2 py-2">Discard</button>
                      <span className="text-[11px] text-amber-700">Unsaved changes</span>
                    </>
                  )}
                </div>
              </div>
            )}
          </div>
        );
      })}

      {!loading && rows.length > 0 && visible.length === 0 && (
        <div className="bg-white border rounded-xl p-6 text-center text-sm text-slate-500">
          No users match “{q.trim()}”.
        </div>
      )}
    </div>
  );
}
