import { useEffect, useMemo, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { api, agentName, initials, contactName, fmtPhone } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import Modal from '../components/Modal';
import { useSocket } from '../context/SocketContext';

const COLORS = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'];
const SECRET_QUESTIONS = [
  'What was the name of your first pet?',
  'What city were you born in?',
  "What is your mother's maiden name?",
  'What was your first car?',
  'What is your favorite food?',
];
const digits = (v) => String(v ?? '').replace(/\D/g, '');
const ONLINE_WINDOW_S = 180; // last_seen_at within 3 min => Online
const isOnline = (a) => {
  if (!a?.last_seen_at) return false;
  const t = new Date(String(a.last_seen_at).replace(' ', 'T')).getTime();
  return Number.isFinite(t) && (Date.now() - t) / 1000 < ONLINE_WINDOW_S;
};
const asNumbers = (raw) => {
  const arr = Array.isArray(raw) ? raw : (raw?.numbers || raw?.data || []);
  return arr.map((n) => (typeof n === 'string' ? n : (n?.number || n?.['sms-number'] || n?.digits || ''))).filter(Boolean);
};
// Workload = active conversations only (archived/spam don't count).
const assignedSessions = (sessions, meta, agentId) => (sessions || []).filter((s) =>
  String(meta[String(s['messagesession-id'])]?.agent_id || '') === String(agentId)
  && (meta[String(s['messagesession-id'])]?.status || 'active') === 'active');
const buildContactMap = (contacts) => {
  const map = {};
  (contacts || []).forEach((c) => {
    ['phonenumber-cell', 'phonenumber-work', 'phonenumber-home', 'phonenumber-fax'].forEach((k) => {
      const d = digits(c[k]);
      if (d) map[d] = c;
      if (d.length === 11 && d.startsWith('1')) map[d.slice(1)] = c;
      if (d.length === 10) map['1' + d] = c;
    });
  });
  return map;
};

export default function Agents() {
  const { user } = useAuth();
  if (user?.role === 'agent') return <Navigate to="/app/settings" replace />;
  return <AgentAdmin />;
}

/* ================================ ADMIN ================================ */

function AgentAdmin() {
  const [agents, setAgents] = useState([]);
  const [meta, setMeta] = useState({});
  const [sessions, setSessions] = useState([]);
  const [contacts, setContacts] = useState([]);
  const [numbers, setNumbers] = useState([]);
  const [activeId, setActiveId] = useState(null);
  const [editing, setEditing] = useState(null); // null | {isNew,...} | agent
  const [pwTarget, setPwTarget] = useState(null);
  const [delTarget, setDelTarget] = useState(null);
  const [delPreview, setDelPreview] = useState(null);
  const [delName, setDelName] = useState('');
  const [delPw, setDelPw] = useState('');
  const [delBusy, setDelBusy] = useState(false);
  const { lastSync } = useSocket();

  const reload = () => {
    api.agents().then(setAgents).catch((e) => toastError('Failed to load agents: ' + e.message));
    api.convoMeta().then(setMeta).catch(() => {});
    api.sessions().then(setSessions).catch(() => {});
    api.contacts().then(setContacts).catch(() => {});
    api.smsNumbers().then((r) => setNumbers(asNumbers(r))).catch(() => {});
  };
  useEffect(() => {
    reload();
    const onMeta = () => api.convoMeta().then(setMeta).catch(() => {});
    window.addEventListener('convo-meta-changed', onMeta);
    const t = setInterval(() => api.agents().then(setAgents).catch(() => {}), 60000); // presence refresh
    return () => { window.removeEventListener('convo-meta-changed', onMeta); clearInterval(t); };
  }, []);

  // Another instance changed agents / assignments / inbox -> refresh counts.
  useEffect(() => {
    if (!lastSync) return;
    if (lastSync.resource === 'agents') api.agents().then(setAgents).catch(() => {});
    if (lastSync.resource === 'convo-meta') api.convoMeta().then(setMeta).catch(() => {});
    if (lastSync.resource === 'sessions') api.sessions().then(setSessions).catch(() => {});
    if (lastSync.resource === 'contacts') api.contacts().then(setContacts).catch(() => {});
  }, [lastSync]);

  const contactByPhone = useMemo(() => buildContactMap(contacts), [contacts]);
  const active = agents.find((a) => String(a.id) === String(activeId));
  const activeSessions = active ? assignedSessions(sessions, meta, active.id) : [];

  const flipStatus = (a) => {
    const to = (a.status || 'active') === 'active' ? 'deactivated' : 'active';
    const verb = to === 'active' ? 'Reactivate' : 'Deactivate';
    if (!confirm(`${verb} ${agentName(a)}?${to === 'deactivated' ? ' They will be signed out and cannot sign back in.' : ''}`)) return;
    api.updateAgent(a.id, { status: to })
      .then(() => { reload(); toastSuccess(to === 'active' ? 'Agent reactivated' : 'Agent deactivated'); })
      .catch((e) => toastError(e?.response?.data?.message || e.message));
  };

  const openDel = async (a) => {
    setDelTarget(a); setDelPreview(null); setDelName(''); setDelPw('');
    try { setDelPreview(await api.agentDeletePreview(a.id)); }
    catch (e) { toastError(e?.response?.data?.message || e.message); setDelTarget(null); }
  };
  const doDelete = async (e) => {
    e.preventDefault();
    setDelBusy(true);
    try {
      await api.deleteAgent(delTarget.id, { confirm_username: delName.trim(), admin_password: delPw });
      setDelTarget(null); setActiveId(null); reload();
      toastSuccess('Agent deleted.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Delete failed.'); }
    finally { setDelBusy(false); }
  };

  return (
    <div className="h-full flex flex-col md:flex-row min-h-0">
      <div className="w-full md:w-80 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
        <div className="p-3 border-b">
          <button onClick={() => setEditing({ isNew: true, first_name: '', last_name: '', username: '', tag_color: COLORS[0], secret_question: SECRET_QUESTIONS[0] })}
            className="w-full bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg py-2">+ Add Agent</button>
        </div>
        <div className="flex-1 overflow-y-auto chat-scroll">
          {agents.map((a) => {
            const list = assignedSessions(sessions, meta, a.id);
            const unread = list.filter((s) => s['messagesession-last-status'] === 'unread').length;
            const off = (a.status || 'active') !== 'active';
            return (
              <button key={a.id} onClick={() => setActiveId(a.id)}
                className={`w-full text-left px-3 py-2.5 border-b flex items-center gap-3 ${String(activeId) === String(a.id) ? 'bg-brand-50' : 'hover:bg-slate-50'} ${off ? 'opacity-60' : ''}`}>
                <span className="w-10 h-10 rounded-full text-white flex items-center justify-center text-sm font-bold shrink-0"
                  style={{ backgroundColor: a.tag_color }}>{initials(agentName(a))}</span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-1.5">
                    <span className="block text-sm font-medium text-slate-800 truncate">{agentName(a)}</span>
                    {!off && isOnline(a) && <span className="text-[10px] font-semibold text-emerald-700 bg-emerald-100 rounded-full px-1.5 py-0.5 shrink-0">● Online</span>}
                    {off && <span className="text-[10px] font-semibold text-slate-500 bg-slate-200 rounded-full px-1.5 py-0.5 shrink-0">Deactivated</span>}
                    {a.is_admin && <span className="text-[10px] font-semibold text-brand-700 bg-brand-50 rounded-full px-1.5 py-0.5 shrink-0">👑 Admin</span>}
                  </span>
                  {a.username && <span className="block text-[11px] text-slate-400 truncate">{a.username}@{a.domain || ''}</span>}
                  <span className="block text-xs text-slate-500">
                    {list.length} conversation{list.length === 1 ? '' : 's'}
                    {unread > 0 && <span className="text-red-600 font-semibold"> • {unread} unread</span>}
                  </span>
                </span>
              </button>
            );
          })}
          {agents.length === 0 && <div className="p-6 text-sm text-slate-400 text-center">No agents yet.</div>}
        </div>
      </div>

      <div className="flex-1 bg-slate-50 p-6 overflow-y-auto chat-bg">
        {!active ? (
          <div className="text-sm text-slate-400">Select an agent — or add one so conversations can be assigned in the Messages inbox.</div>
        ) : (
          <div className="bg-white rounded-xl border p-6 max-w-lg">
            <div className="flex items-center gap-4 mb-4">
              <span className="w-16 h-16 rounded-full text-white flex items-center justify-center text-xl font-bold"
                style={{ backgroundColor: active.tag_color }}>{initials(agentName(active))}</span>
              <div className="min-w-0">
                <h2 className="text-xl font-bold text-slate-900 flex items-center gap-2">
                  {agentName(active)}
                  {isOnline(active) && (active.status || 'active') === 'active' && (
                    <span className="text-[11px] font-semibold text-emerald-700 bg-emerald-100 rounded-full px-2 py-0.5">● Online</span>
                  )}
                </h2>
                <div className="text-sm text-slate-500">
                  {active.username ? <span className="font-mono text-[13px]">{active.username}@{active.domain || ''}</span> : <span className="italic">no login yet</span>}
                  {' • '}
                  {(active.status || 'active') === 'active'
                    ? <span className="text-emerald-600 font-medium">Active</span>
                    : <span className="text-slate-500 font-medium">Deactivated</span>}
                </div>
                <div className="text-xs text-slate-400 mt-0.5">
                  Default number: {active.default_number ? fmtPhone(active.default_number) : '—'}{active.allowed_numbers?.length > 0 && <> • Can send from: {[...new Set([active.default_number, ...(active.allowed_numbers || [])].filter(Boolean))].map((x) => fmtPhone(x)).join(', ')}</>}
                  {active.last_seen_at && <> • Last seen {new Date(String(active.last_seen_at).replace(' ', 'T')).toLocaleString()}</>}
                </div>
              </div>
            </div>
            <h3 className="text-xs font-semibold text-slate-500 mb-1">
              Handling {activeSessions.length} conversation{activeSessions.length === 1 ? '' : 's'}
            </h3>
            <div className="border rounded-lg max-h-56 overflow-y-auto chat-scroll divide-y mb-4">
              {activeSessions.map((s) => {
                const c = contactByPhone[digits(s['messagesession-remote'])];
                const unread = s['messagesession-last-status'] === 'unread';
                return (
                  <div key={s['messagesession-id']} className="px-3 py-2 text-xs flex items-center gap-2">
                    {unread && <span className="w-2 h-2 rounded-full bg-red-500 shrink-0" />}
                    <span className="font-medium text-slate-700 truncate flex-1">
                      {c ? contactName(c) : fmtPhone(s['messagesession-remote'])}
                    </span>
                    <span className="text-slate-400 truncate max-w-[50%]">{s['messagesession-last-message']}</span>
                  </div>
                );
              })}
              {activeSessions.length === 0 && <div className="p-3 text-xs text-slate-400">Nothing assigned — assign conversations from the Messages inbox.</div>}
            </div>
            {!active.is_admin ? (
            <div className="flex flex-wrap gap-2">
              <button onClick={() => setEditing({ ...active })} className="bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg px-4 py-2">Update</button>
              <button onClick={() => setPwTarget(active)} className="border text-sm rounded-lg px-4 py-2 hover:bg-slate-50">Set password</button>
              <button onClick={() => flipStatus(active)}
                className={`text-sm rounded-lg px-4 py-2 border ${(active.status || 'active') === 'active'
                  ? 'border-red-200 text-red-600 hover:bg-red-50'
                  : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'}`}>
                {(active.status || 'active') === 'active' ? 'Deactivate' : 'Reactivate'}
              </button>
              <button onClick={() => openDel(active)}
                className="text-sm rounded-lg px-4 py-2 border border-red-200 text-red-600 hover:bg-red-50">
                Delete…
              </button>
            </div>
            ) : (
              <p className="text-xs text-slate-500 bg-slate-50 border rounded-lg p-2.5">👑 Admin account — created with the tenant. It can't be edited or deleted.</p>
            )}
          </div>
        )}
      </div>

      {editing && (
        <AgentForm initial={editing} numbers={numbers} onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); toastSuccess('Agent saved'); }} />
      )}
      {pwTarget && (
        <SetPasswordModal agent={pwTarget} onClose={() => setPwTarget(null)}
          onSaved={() => { setPwTarget(null); reload(); toastSuccess('Password updated — agent signed out everywhere'); }} />
      )}
      {delTarget && (() => {
        // No username (never given a login) → confirm against the full name,
        // so the prompt is never blank and the delete is always possible.
        const confirmWord = String(delTarget.username || '').trim()
          || String(agentName(delTarget) || '').trim();
        const typedOk = confirmWord === ''
          || delName.trim().toLowerCase() === confirmWord.toLowerCase();
        return (
        <Modal onClose={() => setDelTarget(null)}>
          <h2 className="text-lg font-bold text-red-600 mb-1">Delete {agentName(delTarget)}?</h2>
          <p className="text-xs text-slate-500 mb-3">Permanent. Their conversations are unassigned (not deleted); their pending scheduled messages are cancelled.</p>
          {!delPreview ? <div className="text-sm text-slate-500 mb-3">Loading impact…</div> : (
            <ul className="text-xs bg-slate-50 border rounded-lg p-3 space-y-1 mb-3">
              <li className="flex justify-between"><span className="text-slate-500">Conversations to unassign</span><strong>{delPreview.counts.assigned_conversations}</strong></li>
              <li className="flex justify-between"><span className="text-slate-500">Scheduled to cancel</span><strong>{delPreview.counts.pending_scheduled}</strong></li>
              <li className="flex justify-between"><span className="text-slate-500">Reset tokens to delete</span><strong>{delPreview.counts.reset_requests}</strong></li>
            </ul>
          )}
          <form onSubmit={doDelete} className="space-y-3">
            {confirmWord ? (
              <div>
                <label className="text-xs font-medium text-slate-600">
                  {delTarget.username
                    ? <>Type this agent's login username <strong className="font-mono">{confirmWord}</strong> to confirm</>
                    : <>This agent has no login — type their full name <strong>{confirmWord}</strong> to confirm</>}
                </label>
                <input value={delName} onChange={(e) => setDelName(e.target.value)} placeholder={confirmWord} autoComplete="off"
                  className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
                <p className="text-[11px] text-slate-400 mt-0.5">
                  {delTarget.username ? 'Shown on their row as username@tenant.' : 'No username was ever set for this agent.'}
                </p>
              </div>
            ) : (
              <p className="text-xs text-slate-500">This agent has no username or name on file — your admin password alone confirms the delete.</p>
            )}
            <div><label className="text-xs font-medium text-slate-600">Your admin password</label>
              <input type="password" value={delPw} onChange={(e) => setDelPw(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" /></div>
            <div className="flex gap-2 justify-end">
              <button type="button" onClick={() => setDelTarget(null)} className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
              <button disabled={delBusy || !typedOk || !delPw.trim()}
                className="text-sm bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
                {delBusy ? 'Deleting…' : 'Delete forever'}
              </button>
            </div>
          </form>
        </Modal>
        );
      })()}
    </div>
  );
}

function AgentForm({ initial, numbers, onClose, onSaved }) {
  const [first, setFirst] = useState(initial.first_name || '');
  const [last, setLast] = useState(initial.last_name || '');
  const [username, setUsername] = useState(initial.username || '');
  const [password, setPassword] = useState('');
  const [question, setQuestion] = useState(initial.secret_question || SECRET_QUESTIONS[0]);
  const [answer, setAnswer] = useState('');
  const [color, setColor] = useState(initial.tag_color || COLORS[0]);
  const { user } = useAuth();
  const main = digits(user?.main_number); // tenant main SMS number: locked on for every agent
  const [busy, setBusy] = useState(false);
  const isNew = !!initial.isNew || !initial.id;

  const save = async () => {
    if (!first.trim()) return toastError('First name is required.');
    if (!last.trim()) return toastError('Last name is required.');
    if (isNew) {
      if (!/^[a-z0-9]+$/.test(username.trim().toLowerCase())) return toastError('Username: lowercase letters and digits only.');
      if (password.length < 8) return toastError('Password needs at least 8 characters.');
      if (!answer.trim()) return toastError('Secret answer is required (for password resets).');
    }
    if (!/^#[0-9a-fA-F]{6}$/.test(color)) return toastError('Pick a valid tag color.');
    setBusy(true);
    try {
      const payload = { first_name: first.trim(), last_name: last.trim(), tag_color: color };
      if (isNew) {
        Object.assign(payload, { username: username.trim().toLowerCase(), password, secret_question: question, secret_answer: answer.trim() });
        if (main) payload.allowed_numbers = [main]; // numbers are assigned in Inventory; main stays on every agent
        await api.createAgent(payload);
      } else {
        // Secret Q&A: only sent when a new answer is typed (blank = keep current).
        if (answer.trim()) {
          payload.secret_question = question;
          payload.secret_answer = answer.trim();
        }
        await api.updateAgent(initial.id, payload);
      }
      onSaved();
    } catch (e) { toastError('Save failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';
  return (
    <Modal onClose={onClose}>
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-lg font-bold">{isNew ? 'Add Agent' : 'Update Agent'}</h2>
        <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
      </div>
      <div className="grid grid-cols-2 gap-2 mb-2">
        <div><label className="text-xs font-medium text-slate-600">First name *</label>
          <input value={first} onChange={(e) => setFirst(e.target.value)} className={input} /></div>
        <div><label className="text-xs font-medium text-slate-600">Last name *</label>
          <input value={last} onChange={(e) => setLast(e.target.value)} className={input} /></div>
      </div>
      {isNew ? (
        <>
          <div className="grid grid-cols-2 gap-2 mb-2">
            <div><label className="text-xs font-medium text-slate-600">Username *</label>
              <input value={username} onChange={(e) => setUsername(e.target.value)} placeholder="maria" className={input} />
              <p className="text-[11px] text-slate-400 mt-0.5">Signs in as username@tenant.</p></div>
            <div><label className="text-xs font-medium text-slate-600">Password (min 8) *</label>
              <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} className={input} /></div>
          </div>
          <div className="grid grid-cols-2 gap-2 mb-2">
            <div><label className="text-xs font-medium text-slate-600">Secret question *</label>
              <select value={question} onChange={(e) => setQuestion(e.target.value)} className={input}>
                {SECRET_QUESTIONS.map((q) => <option key={q} value={q}>{q}</option>)}
              </select></div>
            <div><label className="text-xs font-medium text-slate-600">Secret answer *</label>
              <input value={answer} onChange={(e) => setAnswer(e.target.value)} className={input} /></div>
          </div>
        </>
      ) : (
        <>
        <div className="mb-2 text-sm bg-slate-50 border rounded-lg p-2.5">
          <span className="text-slate-500">Login: </span>
          <span className="font-mono">{initial.username ? `${initial.username}@${initial.domain || ''}` : '—'}</span>
          <span className="text-slate-400 text-xs"> (usernames can't be changed — password via “Set password”)</span>
        </div>
        <div className="grid grid-cols-2 gap-2 mb-2">
          <div><label className="text-xs font-medium text-slate-600">Secret question</label>
            <select value={question} onChange={(e) => setQuestion(e.target.value)} className={input}>
              {SECRET_QUESTIONS.map((q) => <option key={q} value={q}>{q}</option>)}
            </select></div>
          <div><label className="text-xs font-medium text-slate-600">New secret answer</label>
            <input value={answer} onChange={(e) => setAnswer(e.target.value)} placeholder="blank = keep current" className={input} /></div>
        </div>
        </>
      )}
      <label className="text-xs font-medium text-slate-600">Tag color *</label>
      <div className="flex items-center gap-2 mt-1.5 mb-2">
        {COLORS.map((c) => (
          <button key={c} onClick={() => setColor(c)} title={c}
            className={`w-8 h-8 rounded-full border-2 ${color === c ? 'border-slate-800 scale-110' : 'border-transparent'}`}
            style={{ backgroundColor: c }} />
        ))}
        <input type="color" value={color} onChange={(e) => setColor(e.target.value)} title="Custom color"
          className="w-8 h-8 rounded cursor-pointer" />
        <span className="text-xs text-slate-500">{color}</span>
      </div>
      <p className="text-xs text-slate-500 bg-slate-50 border rounded-lg p-2.5 mb-2">📦 SMS numbers are assigned in <span className="font-medium">Numbers</span> (System menu) after creating the agent.</p>
      <div className="flex items-center gap-3 mt-3 bg-slate-50 border rounded-lg p-2.5">
        <span className="w-10 h-10 rounded-full text-white flex items-center justify-center text-sm font-bold"
          style={{ backgroundColor: color }}>{initials(`${first} ${last}`) || '?'}</span>
        <span className="text-xs text-slate-500">Preview — this badge overlays conversations {first || 'the agent'} is handling.</span>
      </div>
      <button onClick={save} disabled={busy} className="mt-4 w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
        {busy ? 'Saving…' : isNew ? 'Add Agent' : 'Save Changes'}
      </button>
    </Modal>
  );
}

function SetPasswordModal({ agent, onClose, onSaved }) {
  const [pw1, setPw1] = useState('');
  const [pw2, setPw2] = useState('');
  const [adminPw, setAdminPw] = useState('');
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (pw1.length < 8) return toastError('New password needs at least 8 characters.');
    if (pw1 !== pw2) return toastError('Passwords do not match.');
    if (!adminPw) return toastError('Enter YOUR admin password to confirm.');
    setBusy(true);
    try {
      await api.setAgentPassword(agent.id, adminPw, pw1);
      onSaved();
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
    finally { setBusy(false); }
  };

  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';
  return (
    <Modal onClose={onClose}>
      <div className="flex items-center justify-between mb-1">
        <h2 className="text-lg font-bold">Set password</h2>
        <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
      </div>
      <p className="text-sm text-slate-500 mb-3">For <span className="font-medium text-slate-700">{agentName(agent)}</span> — they are signed out everywhere and must use the new password.</p>
      <div className="space-y-2">
        <div><label className="text-xs font-medium text-slate-600">New password (min 8)</label>
          <input type="password" value={pw1} onChange={(e) => setPw1(e.target.value)} className={input} /></div>
        <div><label className="text-xs font-medium text-slate-600">Confirm new password</label>
          <input type="password" value={pw2} onChange={(e) => setPw2(e.target.value)} className={input} /></div>
        <div><label className="text-xs font-medium text-slate-600">Your admin password (to confirm it's you)</label>
          <input type="password" value={adminPw} onChange={(e) => setAdminPw(e.target.value)} className={input} /></div>
      </div>
      <button onClick={save} disabled={busy} className="mt-4 w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
        {busy ? 'Saving…' : 'Set password'}
      </button>
    </Modal>
  );
}

