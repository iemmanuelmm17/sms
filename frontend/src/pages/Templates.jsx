import { useEffect, useState } from 'react';
import { api, fmtDateTime } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';
import useEscape from '../lib/useEscape';

export default function Templates() {
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const isOwn = (t) => t && String(t.created_by) === 'agent:' + String(user?.id);
  const [list, setList] = useState([]);
  const [editing, setEditing] = useState(null);
  const [name, setName] = useState('');
  const [body, setBody] = useState('');
  const [keyword, setKeyword] = useState('');
  const [shared, setShared] = useState(true);
  const [busy, setBusy] = useState(false);
  const editingTpl = editing && editing !== 'new' ? list.find((x) => String(x.id) === String(editing)) : null;
  const { lastSync } = useSocket();
  useEscape(() => setEditing(null), !!editing); // Esc closes the editor (no Modal wrapper here)

  const reload = () => api.templates().then(setList);
  useEffect(() => { reload(); }, []);

  // Another instance changed templates → refresh.
  useEffect(() => {
    if (lastSync?.resource === 'templates') reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  const startNew = () => { setEditing('new'); setName(''); setBody(''); setShared(true); setKeyword(''); };
  const startEdit = (t) => { setEditing(t.id); setName(t.name); setBody(t.body); setShared(!!t.shared); setKeyword(t.keyword || ''); };

  const save = async () => {
    if (!name.trim() || !body.trim()) return toastError('Name and body are required.');
    setBusy(true);
    try {
      if (editing === 'new') await api.createTemplate({ name, body, shared, keyword });
      else await api.updateTemplate(editing, { name, body, shared, keyword });
      setEditing(null); reload(); toastSuccess('Template saved');
    } catch (e) { toastError('Save failed: ' + e.message); }
    finally { setBusy(false); }
  };

  return (
    <div className="h-full flex flex-col md:flex-row min-h-0">
      <div className="w-full md:w-80 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
        <div className="p-3 border-b">
          <button onClick={startNew} className="w-full bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg py-2">+ New Template</button>
        </div>
        {isAgent && <div className="px-3 py-1.5 border-b text-[11px] text-slate-400">You can edit only templates you created.</div>}
        <div className="flex-1 overflow-y-auto chat-scroll">
          {list.map((t) => (
            <button key={t.id} onClick={() => startEdit(t)}
              className={`w-full text-left px-3 py-2.5 border-b ${editing === t.id ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
              <div className="text-sm font-semibold text-slate-800">📝 {t.name}</div>
              {t.keyword && <div className="text-[11px] font-semibold text-brand-700">/{t.keyword}</div>}
              <div className="text-xs text-slate-500 truncate">{t.body}</div>
            </button>
          ))}
          {list.length === 0 && <div className="p-6 text-sm text-slate-400 text-center">No templates yet.</div>}
        </div>
      </div>
      <div className="flex-1 bg-slate-50 p-6 overflow-y-auto chat-bg">
        {!editing ? (
          <div className="text-sm text-slate-400">Select a template to edit, or create a new one.<br />
            <span className="text-xs">Placeholders like <code className="bg-white border rounded px-1">$FirstName</code>, <code className="bg-white border rounded px-1">$LastName</code>, <code className="bg-white border rounded px-1">$CompanyName</code>, <code className="bg-white border rounded px-1">$AgentName</code> auto-fill when inserted in chat. Type <code className="bg-white border rounded px-1">$</code> in chat to pick one.</span>
          </div>
        ) : (isAgent && editingTpl && !isOwn(editingTpl)) ? (
          <AgentTemplateView t={editingTpl} />
        ) : (
          <div className="bg-white rounded-xl border p-6 max-w-xl">
            <h2 className="text-lg font-bold mb-3">{editing === 'new' ? 'New Template' : 'Edit Template'}</h2>
            {editing !== 'new' && (() => { const t = list.find((x) => String(x.id) === String(editing)); return t && t.created_at ? (
              <div className="text-[11px] text-slate-400 mb-3">Created by {t.created_by_name || t.created_by || 'System'}{` • ${fmtDateTime(t.created_at)}`}{t.updated_at && t.updated_at !== t.created_at ? ` • Updated by ${t.updated_by_name || t.updated_by || 'System'} • ${fmtDateTime(t.updated_at)}` : ''}</div>
            ) : null; })()}
            <label className="text-xs font-medium">Name *</label>
            <input value={name} onChange={(e) => setName(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-3" />
            <label className="text-xs font-medium">Slash keyword <span className="text-slate-400 font-normal">(optional — type /keyword in chat to use)</span></label>
            <input value={keyword} onChange={(e) => setKeyword(e.target.value)} placeholder="e.g. hours"
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-3" />
            <label className="text-xs font-medium">Message body *</label>
            <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={5} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
            <p className="text-[11px] text-slate-400 mt-1"><code>$FirstName</code> / <code>$LastName</code> fill from the contact, <code>$CompanyName</code> is your company name, <code>$AgentName</code> is your name — resolved when inserted or sent.</p>
            <label className="flex items-center gap-2 text-sm mt-2">
              <input type="checkbox" checked={shared} onChange={(e) => setShared(e.target.checked)} /> Shared with domain
            </label>
            <div className="flex gap-2 mt-4">
              <button onClick={save} disabled={busy} className="bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-5 py-2">{busy ? 'Saving…' : 'Save'}</button>
              <button onClick={() => setEditing(null)} className="border text-sm rounded-lg px-5 py-2 hover:bg-slate-50">Cancel</button>
              {editing !== 'new' && (
                <button onClick={() => { if (confirm('Delete this template?')) api.deleteTemplate(editing).then(() => { setEditing(null); reload(); }); }}
                  className="ml-auto border border-red-200 text-red-600 text-sm rounded-lg px-4 py-2 hover:bg-red-50">Delete</button>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function AgentTemplateView({ t }) {
  if (!t) return <div className="text-sm text-slate-400">Select a template to preview it.</div>;
  return (
    <div className="bg-white rounded-xl border p-6 max-w-xl">
      <h2 className="text-lg font-bold mb-1">📝 {t.name}</h2>
      {t.keyword && <div className="text-xs font-semibold text-brand-700 mb-2">/{t.keyword} — type this in chat to use it</div>}
      <div className="text-sm text-slate-700 whitespace-pre-wrap border rounded-lg p-3 bg-slate-50">{t.body}</div>
      <p className="text-[11px] text-slate-400 mt-2"><code>$FirstName</code> / <code>$LastName</code> fill from the contact, <code>$CompanyName</code> is your company name, <code>$AgentName</code> is your name — resolved when inserted or sent.</p>
    </div>
  );
}
