import { useEffect, useState, useRef } from 'react';
import { api, contactName, initials, avatarColor, fmtPhone, fmtDateTime, primaryPhone, contactId, hasSmsNumber } from '../api/client';
import ContactForm, { EMPTY_CONTACT } from '../components/ContactForm';
import { toastError, toastSuccess } from '../lib/toast';
import { contactMatches } from './Scheduler';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';

// Selection key: real id when present, else phone+name fallback (never undefined).
const selKey = (c) => contactId(c) || `${primaryPhone(c)}|${contactName(c)}`;

export default function Contacts() {
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const [list, setList] = useState([]);
  const [q, setQ] = useState('');
  const [showExtensions, setShowExtensions] = useState(false);
  const [activeId, setActiveId] = useState(null);
  const [editing, setEditing] = useState(null); // null | {} for new | contact for edit
  const [importRes, setImportRes] = useState(null);
  const [syncing, setSyncing] = useState(false);
  const [syncInfo, setSyncInfo] = useState(null);
  const [companies, setCompanies] = useState([]);
  const fileRef = useRef(null);
  const { lastSync } = useSocket();

  const reload = () => {
    api.contacts().then(setList).catch((e) => toastError('Failed to load: ' + e.message));
    api.contactSyncStatus().then(setSyncInfo).catch(() => {});
  };
  // Manual two-way sync with the Dynalink portal (admin only):
  // portal edits come in, contacts added here get pushed up.
  const resync = async () => {
    setSyncing(true);
    try {
      const r = await api.resyncContacts();
      setSyncInfo({ count: r.count, last_synced_at: r.last_synced_at });
      reload();
      const pushed = r.pushed ? `, ${r.pushed} pushed to portal` : '';
      const removed = r.removed ? `, ${r.removed} removed` : '';
      const failed = r.errors?.length ? ` (${r.errors.length} warning(s))` : '';
      toastSuccess(`Synced — ${r.created || 0} added, ${r.updated || 0} updated${pushed}${removed}${failed}`);
    } catch (e) { toastError(e?.response?.data?.message || 'Resync failed.'); }
    finally { setSyncing(false); }
  };
  useEffect(() => {
    reload();
    api.companies().then(setCompanies).catch(() => {});
    const onChanged = () => reload();
    window.addEventListener('contacts-changed', onChanged);
    return () => window.removeEventListener('contacts-changed', onChanged);
  }, []);

  // Another instance changed contacts / companies → refresh.
  useEffect(() => {
    if (!lastSync) return;
    if (lastSync.resource === 'contacts') reload();
    if (lastSync.resource === 'companies') api.companies().then(setCompanies).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  // Extension-only contacts (no 10+ digit number) are hidden by default —
  // short work extensions can't receive SMS/MMS.
  const hiddenCount = list.filter((c) => !hasSmsNumber(c)).length;
  const filtered = list.filter((c) => {
    if (!showExtensions && !hasSmsNumber(c)) return false;
    return contactMatches(c, q);
  });
  const [listLimit, setListLimit] = useState(100);
  const shownContacts = filtered.length > listLimit ? filtered.slice(0, listLimit) : filtered;
  const active = list.find((c) => selKey(c) === activeId);

  const downloadTemplate = () => {
    if (!api.isDemo) { window.location.href = api.contactsTemplateUrl(); return; }
    const blob = new Blob([api.demoCsvTemplate()], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = 'contacts_template.csv'; a.click();
  };

  const onImportFile = async (f) => {
    if (!f) return;
    try {
      const res = await api.importContacts(f);
      setImportRes(res); reload();
    } catch (e) { toastError('Import failed: ' + (e?.response?.data?.message || e.message)); }
  };

  return (
    <div className="h-full flex flex-col md:flex-row min-h-0">
      <div className="w-full md:w-80 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
        <div className="p-3 border-b space-y-2">
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔍 Search contacts…"
            className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          <div className="flex gap-2">
            <button onClick={() => setEditing({ ...EMPTY_CONTACT })} className="flex-1 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-lg py-2">+ Add Contact</button>
            <button onClick={() => fileRef.current?.click()} title="Upload CSV" className="flex-1 border text-xs font-medium rounded-lg py-2 hover:bg-slate-50">⬆ Upload CSV</button>
            <input ref={fileRef} type="file" accept=".csv" className="hidden" onChange={(e) => onImportFile(e.target.files?.[0])} />
          </div>
          {!isAgent && (
            <button onClick={resync} disabled={syncing}
              className="w-full border text-[11px] font-medium rounded-lg py-1.5 hover:bg-slate-50 disabled:opacity-50">
              {syncing ? '⟳ Resyncing…' : '⟳ Resync from portal'}
            </button>
          )}
          {syncInfo?.last_synced_at && (
            <p className="text-[11px] text-slate-400">
              Synced {fmtDateTime(syncInfo.last_synced_at)}{syncInfo.count != null ? ` • ${syncInfo.count} in local database` : ''}
            </p>
          )}
          {!isAgent && (
            <p className="text-[11px] text-slate-400">
              Contacts load from the local database. Resync pulls edits made directly in the portal and pushes contacts added here back up.
            </p>
          )}
          <button onClick={downloadTemplate} className="w-full text-[11px] text-brand-600 hover:underline">⬇ Download CSV template</button>
          {hiddenCount > 0 && (
            <label className="flex items-center gap-2 text-[11px] text-slate-500">
              <input type="checkbox" checked={showExtensions} onChange={(e) => setShowExtensions(e.target.checked)} />
              Show {hiddenCount} extension-only contact{hiddenCount === 1 ? '' : 's'} (hidden — no SMS number)
            </label>
          )}
          {importRes && (
            <div className="text-[11px] bg-slate-50 border rounded-lg p-2">
              Imported <strong>{importRes.created}</strong>, failed <strong>{importRes.failed}</strong>
              {importRes.errors?.slice(0, 5).map((e, i) => <div key={i} className="text-red-600">Row {e.row}: {e.error}</div>)}
              <button onClick={() => setImportRes(null)} className="text-slate-400 hover:underline">dismiss</button>
            </div>
          )}
        </div>
        <div className="flex-1 overflow-y-auto chat-scroll">
          {shownContacts.map((c) => {
            const name = contactName(c);
            const key = selKey(c);
            return (
              <button key={key} onClick={() => setActiveId(key)}
                className={`w-full text-left px-3 py-2.5 border-b flex items-center gap-3 ${activeId === key ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
                <span className={`w-10 h-10 rounded-full ${avatarColor(name)} text-white flex items-center justify-center text-sm font-bold shrink-0`}>{initials(name)}</span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-medium text-slate-800 truncate">{name}</span>
                  <span className="block text-xs text-slate-500 truncate">{fmtPhone(primaryPhone(c)) || '—'}</span>
                </span>
                {c.company && (
                  <span title={c.company}
                    className="ml-auto shrink-0 max-w-[45%] truncate rounded-full bg-slate-100 border border-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                    {c.company}
                  </span>
                )}
              </button>
            );
          })}
          {filtered.length > shownContacts.length && (
            <button onClick={() => setListLimit((l) => l + 100)} className="w-full text-center text-xs text-brand-600 hover:underline py-2">
              ↓ Show more ({filtered.length - shownContacts.length} hidden)
            </button>
          )}
          {filtered.length === 0 && <div className="p-6 text-sm text-slate-400 text-center">No contacts.</div>}
        </div>
      </div>

      <div className="flex-1 bg-slate-50 p-6 overflow-y-auto chat-bg">
        {!active ? (
          <div className="text-sm text-slate-400">Select a contact to view details.</div>
        ) : (
          <div className="bg-white rounded-xl border p-6 max-w-lg">
            <div className="flex items-center gap-4 mb-4">
              <span className={`w-16 h-16 rounded-full ${avatarColor(contactName(active))} text-white flex items-center justify-center text-xl font-bold`}>
                {initials(contactName(active))}
              </span>
              <div>
                <h2 className="text-xl font-bold text-slate-900">{contactName(active)}</h2>
                <div className="text-sm text-slate-500">{active.company || 'No company'}</div>
              </div>
            </div>
            <dl className="text-sm space-y-2">
              {[['Cell', active['phonenumber-cell']], ['Work', active['phonenumber-work']], ['Home', active['phonenumber-home']], ['Fax', active['phonenumber-fax']]].map(([l, v]) => (
                <div key={l} className="flex"><dt className="w-20 text-slate-400">{l}</dt><dd className="text-slate-800">{v ? fmtPhone(v) : '—'}</dd></div>
              ))}
              <div className="flex"><dt className="w-20 text-slate-400">Email</dt><dd className="text-slate-800">{active.email || '—'}</dd></div>
              <div className="flex"><dt className="w-20 text-slate-400">Company</dt><dd className="text-slate-800">{active.company || '—'}</dd></div>
            </dl>
            <div className="flex gap-2 mt-5">
              <button onClick={() => setEditing({ ...active })} className="bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg px-4 py-2">Update</button>
              {!isAgent && (
                <button onClick={() => { if (confirm('Delete this contact?')) api.deleteContact(contactId(active)).then(() => { setActiveId(null); reload(); toastSuccess('Contact deleted'); }).catch((e) => toastError(e.message)); }}
                  className="border border-red-200 text-red-600 text-sm rounded-lg px-4 py-2 hover:bg-red-50">Delete</button>
              )}
            </div>
          </div>
        )}
      </div>

      {editing && (
        <ContactForm initial={editing} companies={companies} onClose={() => setEditing(null)}
          onSaved={(saved) => {
            const form = saved || editing;
            setEditing(null); reload();
            // Company with no match yet → auto-create it.
            const company = String(form.company || '').trim();
            if (company && !companies.some((c) => (c.name || '').toLowerCase() === company.toLowerCase())) {
              api.createCompany({ name: company }).then(() => {
                api.companies().then(setCompanies).catch(() => {});
                toastSuccess(`Contact saved — company "${company}" created`);
              }).catch(() => toastSuccess('Contact saved'));
              return;
            }
            toastSuccess('Contact saved');
          }} />
      )}
    </div>
  );
}
