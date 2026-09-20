import { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { api, fmtDateTimeIn, getTimezone } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';

/** Admin-only audit trail viewer (phase 3). Append-only — rows are never edited or deleted. */

const LABELS = {
  'admin.login.success': '✅ Admin signed in',
  'admin.login.fail': '❌ Admin sign-in failed',
  'agent.login.success': '✅ Agent signed in',
  'agent.login.fail': '❌ Agent sign-in failed',
  'agent.login.locked': '🔒 Agent locked out (3 fails)',
  'agent.created': '➕ Agent created',
  'agent.updated': '✏️ Agent updated',
  'agent.password-forced': '🔑 Admin reset agent password',
  'agent.password-changed': '🔑 Agent changed own password',
  'agent.forgot.started': '❓ Password reset started',
  'agent.forgot.bad-answer': '❌ Wrong secret answer',
  'agent.forgot.verified': '✅ Secret answer verified',
  'agent.forgot.completed': '✅ Password reset completed',
  'agent.forgot.denied': '⛔ Reset denied (unknown/deactivated)',
  'admin.login.locked': '🔒 Admin locked out (3 fails)',
  'admin.logout': '👋 Admin signed out',
  'agent.logout': '👋 Agent signed out',
  'ip.login-blocked': '🛡 Sign-in blocked (IP locked)',
  'template.created': '📝 Template created',
  'template.updated': '📝 Template updated',
  'template.deleted': '📝 Template deleted',
  'scheduled.created': '🕒 Scheduled message created',
  'scheduled.updated': '🕒 Scheduled message updated',
  'scheduled.deleted': '🕒 Scheduled message deleted',
  'scheduled.cancelled': '🚫 Scheduled message cancelled',
  'scheduled.send-now': '⚡ Scheduled message sent now',
  'scheduled.retried': '↻ Scheduled failures retried',
  'autoreply.created': '🤖 Auto-reply created',
  'autoreply.updated': '🤖 Auto-reply updated',
  'autoreply.deleted': '🤖 Auto-reply deleted',
  'autoreply.reset': '↩️ Auto-reply reset to default',
  'company-settings.updated': '🏢 Company name updated',
  'number.shared-changed': '🔀 Number sharing changed',
  'number.notify-changed': '🔔 Notify list changed',
  'number.email-toggled': '✉️ Email notification toggled',
  'company.main-changed': '📞 Main line changed',
  'opt-out.added': '⛔ Number opted out',
  'opt-out.removed': '✅ Opt-out removed',
  'api-token.created': '🔑 API token created',
  'api-token.revoked': '🔑 API token revoked',
  'tenant-webhook.created': '🪝 Webhook created',
  'tenant-webhook.updated': '🪝 Webhook updated',
  'tenant-webhook.deleted': '🪝 Webhook deleted',
  'tenant-webhook.tested': '🪝 Webhook tested',
  'conversation.assigned': '👤 Conversation assigned',
  'admin.ip-unblocked': '🔓 Admin unlocked IP',
  'admin.user-unblocked': '🔓 Admin unlocked username',
  'password.changed': '🔑 Password changed',
  'tenant.login.password-expired': '⛔ Password expired at login',
  'agent.login.password-expired': '⛔ Password expired at login',
  'tenant.password-expiry.updated': '⏳ Password expiry window updated',
};
const label = (a) => LABELS[a] || String(a || '').replace(/[._-]+/g, ' ');

const PILL = {
  admin: 'bg-violet-100 text-violet-800',
  agent: 'bg-emerald-100 text-emerald-800',
  unknown: 'bg-slate-200 text-slate-600',
  system: 'bg-sky-100 text-sky-800',
};

const detailText = (row) => {
  const d = row?.detail;
  if (!d || typeof d !== 'object') return '—';
  switch (row.action) {
    case 'agent.created':
      return `Login: ${d.username || ''}${d.agent_id ? ` (#${d.agent_id})` : ''}`;
    case 'agent.updated': {
      const nc = d.number_changes || {};
      const fmtV = (v) => Array.isArray(v) ? (v.join(', ') || '(none)') : (v ?? '(none)');
      const parts = Object.entries(nc).map(([k, v]) => `${k}: ${fmtV(v.from)} → ${fmtV(v.to)}`);
      return `Changed: ${(d.keys || []).join(', ') || '—'}${parts.length ? ` — ${parts.join('; ')}` : ''}`;
    }
    case 'agent.password-forced':
      return d.agent_id ? `Agent #${d.agent_id} signed out everywhere` : '—';
    // Why the password was changed — lets an admin tell "the agent stayed on
    // top of it" apart from "it lapsed and they were forced".
    case 'password.changed': {
      const TRIGGER = {
        voluntary: 'Voluntary — changed by the user',
        forced_expiry: 'Forced — password had expired',
        forgot_password: 'Forgot-password reset',
        admin_reset: 'Admin-set (Create New Password)',
      };
      const why = TRIGGER[d.trigger] || d.trigger || 'Changed';
      const who = d.target_name ? ` for ${d.target_name}` : '';
      const cyc = d.days_applied ? ` — next expiry in ${d.days_applied} days` : '';
      return `${why}${who}${cyc}`;
    }
    case 'tenant.password-expiry.updated':
      return `Window: ${d.from ?? '—'} → ${d.to ?? '—'} days`;
    case 'template.created':
    case 'template.updated':
    case 'template.deleted':
      return `📝 ${d.name || 'Template'}${d.template_id ? ` (#${d.template_id})` : ''}${d.keys?.length ? ` — ${d.keys.join(', ')}` : ''}`;
    case 'scheduled.created':
    case 'scheduled.updated':
    case 'scheduled.deleted':
    case 'scheduled.cancelled':
    case 'scheduled.send-now':
    case 'scheduled.retried':
      return `🕒 ${d.name || 'Scheduled message'}${d.scheduled_id ? ` (#${d.scheduled_id})` : ''}`;
    case 'autoreply.created':
    case 'autoreply.updated':
    case 'autoreply.reset':
    case 'autoreply.deleted':
      return `🤖 ${d.name || 'Auto-reply'}${d.autoreply_id ? ` (#${d.autoreply_id})` : ''}${d.keys?.length ? ` — ${d.keys.join(', ')}` : ''}`;
    case 'company-settings.updated':
      return `Company name → "${d.company_name || '(cleared)'}"`;
    case 'number.shared-changed':
      return `📞 ${d.number || ''} → ${d.shared ? 'shared' : 'not shared'}`;
    case 'number.notify-changed':
      return `📞 ${d.number || ''}: ${(d.notify || []).join('; ') || '(cleared)'}`;
    case 'number.email-toggled':
      return `📞 ${d.number || ''} → ${d.enabled ? 'enabled' : 'disabled'}`;
    case 'company.main-changed':
      return `${d.from || '(none)'} → ${d.to || '(none)'}`;
    case 'opt-out.added':
      return `📵 ${d.phone || ''}${d.number ? ` (via ${d.number})` : ''}${d.note ? ` — ${d.note}` : ''}`;
    case 'opt-out.removed':
      return `📵 ${d.phone || ''}`;
    case 'api-token.created':
    case 'api-token.revoked':
      return `🔑 ${d.name || 'token'}`;
    case 'tenant-webhook.created':
    case 'tenant-webhook.updated':
    case 'tenant-webhook.deleted':
    case 'tenant-webhook.tested':
      return `🪝 ${d.url || ''}${d.keys?.length ? ` — ${d.keys.join(', ')}` : ''}`;
    case 'conversation.assigned': {
      const who = (v) => (v === null || v === undefined ? 'Unassigned' : `Agent #${v}`);
      const sid = String(d.session_id || '');
      return `${sid ? `…${sid.slice(-8)}: ` : ''}${who(d.from)} → ${who(d.to)}`;
    }
    case 'admin.ip-unblocked':
      return `${d.ip || ''} (${d.cleared ?? 0} attempts cleared)`;
    case 'admin.user-unblocked':
      return `${d.username || ''} (${d.cleared ?? 0} attempts cleared)`;
    default: {
      const parts = Object.entries(d).filter(([, v]) => v !== null && v !== '').map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(', ') : v}`);
      return parts.length ? parts.join(' • ') : '—';
    }
  }
};

const EMPTY = { action: '', actor: '', from: '', to: '' };

export default function AuditLog() {
  const { user } = useAuth();
  const [rows, setRows] = useState([]);
  const [actions, setActions] = useState([]);
  const [f, setF] = useState(EMPTY);
  const [applied, setApplied] = useState(EMPTY);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ last_page: 1, total: 0 });
  const [busy, setBusy] = useState(false);
  const [locks, setLocks] = useState({ users: [], ips: [] });
  const [lockBase, setLockBase] = useState(Date.now());
  const [now, setNow] = useState(Date.now());
  const isAgent = user?.role === 'agent';

  const load = async (pg, filt) => {
    if (isAgent) return;
    setBusy(true);
    try {
      const r = await api.auditLogs({ action: filt.action || undefined, actor: filt.actor || undefined,
        from: filt.from || undefined, to: filt.to || undefined, page: pg, per_page: 50 });
      const data = Array.isArray(r) ? r : (r?.data || []);
      setRows(data);
      setMeta({ last_page: r?.last_page || 1, total: r?.total ?? data.length });
      setPage(r?.current_page || pg);
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
    finally { setBusy(false); }
  };

  const loadLocks = async () => {
    if (isAgent) return;
    try {
      const d = await api.lockouts();
      setLocks({ users: d?.users || [], ips: d?.ips || [] });
      setLockBase(Date.now());
    } catch { /* panel stays empty; the list below still works */ }
  };
  const unlockUser = async (username) => {
    try {
      const r = await api.unblockUser(username);
      toastSuccess(`Unlocked ${username} (${r?.cleared ?? 0} attempts cleared)`);
    } catch (e) { toastError(e?.response?.data?.message || e.message); return; }
    loadLocks(); load(page, applied);
  };
  const unlockIp = async (ip) => {
    try {
      const r = await api.unblockIp(ip);
      toastSuccess(`Unlocked ${ip} (${r?.cleared ?? 0} attempts cleared)`);
    } catch (e) { toastError(e?.response?.data?.message || e.message); return; }
    loadLocks(); load(page, applied);
  };

  useEffect(() => {
    if (isAgent) return;
    api.auditActions().then((a) => setActions(Array.isArray(a) ? a : [])).catch(() => {});
    load(1, EMPTY);
    loadLocks();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAgent]);

  // Ticking clock for the retry countdowns + 30s lockout refresh.
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, []);
  useEffect(() => {
    if (isAgent) return;
    const t = setInterval(loadLocks, 30000);
    return () => clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAgent]);

  const fmtLeft = (secs) => {
    const s = Math.max(0, (secs || 0) - Math.floor((now - lockBase) / 1000));
    return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
  };

  if (isAgent) return <Navigate to="/app/messages" replace />;

  const apply = (e) => { e?.preventDefault(); setApplied(f); load(1, f); };
  const reset = () => { setF(EMPTY); setApplied(EMPTY); load(1, EMPTY); };

  const exportCsv = async () => {
    try {
      const all = [];
      let pg = 1, last = 1;
      do {
        const r = await api.auditLogs({ action: applied.action || undefined, actor: applied.actor || undefined,
          from: applied.from || undefined, to: applied.to || undefined, page: pg, per_page: 500 });
        all.push(...(r?.data || r || []));
        last = r?.last_page || 1; pg++;
      } while (pg <= last && all.length < 5000);
      const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
      const csv = ['time,actor,type,event,details,ip',
        ...all.map((row) => [row.created_at, row.actor_name, row.actor_type, label(row.action), detailText(row), row.ip_address].map(esc).join(',')),
      ].join('\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
      a.download = 'audit-log.csv';
      a.click();
      URL.revokeObjectURL(a.href);
      toastSuccess(`Exported ${all.length} row${all.length === 1 ? '' : 's'}`);
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };

  const input = 'border rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 w-full';
  return (
    <div className="h-full overflow-y-auto bg-slate-50 p-4 md:p-6">
      <div className="max-w-5xl mx-auto">
        <div className="flex items-center justify-between mb-1">
          <h2 className="text-lg font-bold text-slate-800">📋 Audit Log</h2>
          <button onClick={exportCsv} className="text-xs border rounded-lg px-3 py-1.5 hover:bg-white text-slate-600">⬇️ Export CSV</button>
        </div>
        <p className="text-xs text-slate-400 mb-4">Sign-ins, lockouts, password resets, agent changes, number settings, opt-outs, templates, scheduled sends, auto-replies, and assignments. Newest first — rows are never edited or deleted.</p>

        <div className="bg-white rounded-xl border p-3 mb-4">
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-sm font-bold text-slate-800">🔒 Active lockouts</h3>
            <button onClick={loadLocks} className="text-xs border rounded-lg px-2.5 py-1 hover:bg-slate-50 text-slate-600">↻ Refresh</button>
          </div>
          {locks.users.length === 0 && locks.ips.length === 0 ? (
            <p className="text-xs text-slate-400">All clear — nobody is locked out right now.</p>
          ) : (
            <div className="grid md:grid-cols-2 gap-3">
              <div>
                <div className="text-[11px] font-semibold text-slate-500 mb-1">USERNAMES</div>
                {locks.users.length === 0 && <p className="text-xs text-slate-400">—</p>}
                {locks.users.map((u) => (
                  <div key={u.username} className="flex items-center gap-2 text-xs py-1 border-b last:border-0">
                    <span className="font-mono text-slate-700 truncate flex-1">{u.username}</span>
                    <span className="text-amber-700 whitespace-nowrap">🔒 {u.fails} fails · retry in {fmtLeft(u.retry_after_secs)}</span>
                    <button onClick={() => unlockUser(u.username)} className="border border-emerald-200 text-emerald-700 rounded-lg px-2 py-0.5 hover:bg-emerald-50 font-semibold">Unlock</button>
                  </div>
                ))}
              </div>
              <div>
                <div className="text-[11px] font-semibold text-slate-500 mb-1">IP ADDRESSES</div>
                {locks.ips.length === 0 && <p className="text-xs text-slate-400">—</p>}
                {locks.ips.map((x) => (
                  <div key={x.ip} className="flex items-center gap-2 text-xs py-1 border-b last:border-0">
                    <span className="font-mono text-slate-700 truncate flex-1">{x.ip}</span>
                    <span className="text-amber-700 whitespace-nowrap">🔒 {x.fails} fails · retry in {fmtLeft(x.retry_after_secs)}</span>
                    <button onClick={() => unlockIp(x.ip)} className="border border-emerald-200 text-emerald-700 rounded-lg px-2 py-0.5 hover:bg-emerald-50 font-semibold">Unlock</button>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <form onSubmit={apply} className="bg-white rounded-xl border p-3 mb-4 grid grid-cols-2 md:grid-cols-6 gap-2">
          <select value={f.action} onChange={(e) => setF({ ...f, action: e.target.value })} className={input}>
            <option value="">All events</option>
            {actions.map((a) => <option key={a} value={a}>{label(a)}</option>)}
          </select>
          <input value={f.actor} onChange={(e) => setF({ ...f, actor: e.target.value })} placeholder="Actor name…" className={input} />
          <input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} title="From" className={input} />
          <input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} title="To" className={input} />
          <button type="submit" disabled={busy} className="bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-4">
            {busy ? '…' : 'Search'}
          </button>
          <button type="button" onClick={reset} className="border text-sm rounded-lg px-4 hover:bg-slate-50">Reset</button>
        </form>

        <div className="bg-white rounded-xl border overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[720px]">
              <thead>
                <tr className="text-left text-xs text-slate-500 border-b bg-slate-50">
                  <th className="font-semibold px-3 py-2 whitespace-nowrap">Time</th>
                  <th className="font-semibold px-3 py-2">Actor</th>
                  <th className="font-semibold px-3 py-2">Event</th>
                  <th className="font-semibold px-3 py-2">Details</th>
                  <th className="font-semibold px-3 py-2 whitespace-nowrap">IP</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-slate-50">
                    <td className="px-3 py-2 text-xs text-slate-500 whitespace-nowrap">{fmtDateTimeIn(r.created_at, getTimezone())}</td>
                    <td className="px-3 py-2">
                      <span className="text-slate-800">{r.actor_name || '—'}</span>{' '}
                      <span className={`text-[10px] font-semibold rounded-full px-1.5 py-0.5 ${PILL[r.actor_type] || PILL.unknown}`}>{r.actor_type}</span>
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap">{label(r.action)}</td>
                    <td className="px-3 py-2 text-xs text-slate-500">{detailText(r)}</td>
                    <td className="px-3 py-2 text-xs text-slate-400 font-mono whitespace-nowrap">{r.ip_address || '—'}</td>
                  </tr>
                ))}
                {rows.length === 0 && (
                  <tr><td colSpan={5} className="p-6 text-sm text-slate-400 text-center">{busy ? 'Loading…' : 'No matching events.'}</td></tr>
                )}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between px-3 py-2 border-t text-xs text-slate-500">
            <span>{meta.total} event{meta.total === 1 ? '' : 's'}</span>
            <span className="flex items-center gap-2">
              <button disabled={page <= 1 || busy} onClick={() => load(page - 1, applied)} className="border rounded-lg px-3 py-1 hover:bg-slate-50 disabled:opacity-40">← Prev</button>
              <span>Page {page} of {meta.last_page}</span>
              <button disabled={page >= meta.last_page || busy} onClick={() => load(page + 1, applied)} className="border rounded-lg px-3 py-1 hover:bg-slate-50 disabled:opacity-40">Next →</button>
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}
