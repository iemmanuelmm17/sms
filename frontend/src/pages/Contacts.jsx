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

  // Bulk selection: Set of selKeys. Actions = set company (all roles, like
  // the single update) and delete (admins only, two-way confirmation).
  const [sel, setSel] = useState(() => new Set());
  const [scopeF, setScopeF] = useState('all');   // all | own | shared
  const [bulkCo, setBulkCo] = useState(false);   // company modal open
  const [bulkDel, setBulkDel] = useState(0);     // 0 closed | 1 warn step | 2 typed-confirm step
  const [delWord, setDelWord] = useState('');
  const [bulkBusy, setBulkBusy] = useState(null); // progress string while a bulk run is in flight

  const reload = () => {
    api.contacts().then((rows) => {
      setList(rows);
      // Drop selections that no longer exist (deleted here or in another window).
      setSel((prev) => {
        if (!prev.size) return prev;
        const keys = new Set((rows || []).map(selKey));
        const next = new Set([...prev].filter((k) => keys.has(k)));
        return next.size === prev.size ? prev : next;
      });
    }).catch((e) => toastError('Failed to load: ' + e.message));
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

  // Another instance changed contacts / companies / groups → refresh.
  useEffect(() => {
    if (!lastSync) return;
    const r = lastSync.resource;
    if (r === 'contacts' || r === 'resync') reload();
    if (r === 'companies' || r === 'resync') api.companies().then(setCompanies).catch(() => {});
    if (r === 'groups' || r === 'resync') reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  // Extension-only contacts (no 10+ digit number) are hidden by default —
  // short work extensions can't receive SMS/MMS.
  const hiddenCount = list.filter((c) => !hasSmsNumber(c)).length;
  const matches = list.filter((c) => {
    if (!showExtensions && !hasSmsNumber(c)) return false;
    return contactMatches(c, q);
  });
  const ownCount = matches.filter((c) => !c.shared).length;
  const sharedCountAll = matches.filter((c) => c.shared).length;
  // Own/Shared filter: shared rows come from the DOMAIN address book and
  // are the same for every user; own rows are the actor's personal book.
  const filtered = scopeF === 'all' ? matches
    : scopeF === 'shared' ? matches.filter((c) => c.shared)
      : matches.filter((c) => !c.shared);
  const [listLimit, setListLimit] = useState(100);
  const shownContacts = filtered.length > listLimit ? filtered.slice(0, listLimit) : filtered;
  const active = list.find((c) => selKey(c) === activeId);

  // ---- Bulk selection helpers ----
  const toggleSel = (key) => setSel((prev) => {
    const n = new Set(prev);
    if (n.has(key)) n.delete(key); else n.add(key);
    return n;
  });
  // "Select all" means the whole FILTERED set, not just the visible slice.
  const allSelected = filtered.length > 0 && filtered.every((c) => sel.has(selKey(c)));
  const toggleAll = () => setSel(allSelected ? new Set() : new Set(filtered.map(selKey)));
  const selectedContacts = list.filter((c) => sel.has(selKey(c)));

  /** Run a bulk endpoint over 200-id chunks (the server cap) with progress. */
  const inBatches = async (ids, fn, label) => {
    const B = 200;
    let ok = 0; let failed = 0; const errs = [];
    for (let i = 0; i < ids.length; i += B) {
      const chunk = ids.slice(i, i + B);
      setBulkBusy(`${label} ${Math.min(i + B, ids.length)}/${ids.length}…`);
      try {
        const r = await fn(chunk);
        ok += Number(r?.updated ?? r?.deleted ?? 0);
        failed += Number(r?.failed || 0);
        (r?.errors || []).forEach((e) => errs.push(e));
      } catch (e) {
        failed += chunk.length;
        errs.push({ id: chunk[0], error: e?.response?.data?.message || e.message });
      }
    }
    return { ok, failed, errs };
  };

  const applyBulkCompany = async (company) => {
    const withId = selectedContacts.filter((c) => contactId(c));
    const skipped = selectedContacts.length - withId.length;
    if (!withId.length) { toastError('None of the selected contacts have a portal id — resync first.'); return; }
    const items = withId.map((c) => ({ id: contactId(c), shared: !!c.shared }));
    const { ok, failed } = await inBatches(items, (chunk) => api.bulkContactsCompany(
      chunk.filter((it) => !it.shared).map((it) => it.id),
      company,
      chunk.filter((it) => it.shared).map((it) => it.id),
    ), 'Updating');
    setBulkBusy(null); setBulkCo(false); setSel(new Set()); reload();
    // Same auto-create as the single-contact save.
    if (ok > 0 && !companies.some((c) => (c.name || '').toLowerCase() === company.toLowerCase())) {
      api.createCompany({ name: company }).then(() => api.companies().then(setCompanies).catch(() => {})).catch(() => {});
    }
    const skipNote = skipped ? ` (${skipped} skipped — no portal id)` : '';
    if (failed) toastError(`Company set on ${ok}; ${failed} failed${skipNote}.`);
    else toastSuccess(`Company set to "${company}" on ${ok} contact${ok === 1 ? '' : 's'}${skipNote}.`);
  };

  const applyBulkDelete = async () => {
    const withId = selectedContacts.filter((c) => contactId(c));
    const skipped = selectedContacts.length - withId.length;
    if (!withId.length) { toastError('None of the selected contacts have a portal id — resync first.'); setBulkDel(0); return; }
    const items = withId.map((c) => ({ id: contactId(c), shared: !!c.shared }));
    const { ok, failed } = await inBatches(items, (chunk) => api.bulkContactsDelete(
      chunk.filter((it) => !it.shared).map((it) => it.id),
      'DELETE',
      chunk.filter((it) => it.shared).map((it) => it.id),
    ), 'Deleting');
    setBulkBusy(null); setBulkDel(0); setDelWord(''); setSel(new Set()); setActiveId(null); reload();
    const skipNote = skipped ? ` (${skipped} skipped — no portal id)` : '';
    if (failed) toastError(`Deleted ${ok}; ${failed} failed${skipNote}.`);
    else toastSuccess(`Deleted ${ok} contact${ok === 1 ? '' : 's'}${skipNote}.`);
  };

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
      <div className="w-full md:w-64 lg:w-80 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
        <div className="p-3 border-b space-y-2">
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔍 Search contacts…"
            className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          <div className="flex rounded-lg border overflow-hidden text-[11px] font-semibold">
            {[['all', `All (${matches.length})`], ['own', `Mine (${ownCount})`], ['shared', `Shared (${sharedCountAll})`]].map(([v, label]) => (
              <button key={v} onClick={() => setScopeF(v)}
                className={`flex-1 py-1.5 ${scopeF === v ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'}`}>
                {label}
              </button>
            ))}
          </div>
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
              Synced {fmtDateTime(syncInfo.last_synced_at)}{syncInfo.count != null ? ` • ${syncInfo.count} mine${syncInfo.shared_count != null ? ` + ${syncInfo.shared_count} shared` : ''} in local database` : ''}
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
        {filtered.length > 0 && (
          <div className="px-3 py-1.5 border-b bg-slate-50 flex items-center gap-2">
            <label className="flex items-center gap-2 text-[11px] font-medium text-slate-600 cursor-pointer select-none">
              <input type="checkbox" checked={allSelected} onChange={toggleAll} className="accent-brand-600 cursor-pointer" />
              Select all{filtered.length > shownContacts.length ? ` (${filtered.length})` : ''}
            </label>
            {sel.size > 0 && <span className="text-[11px] text-brand-700 font-semibold tabular-nums">{sel.size} selected</span>}
            {sel.size > 0 && (
              <button onClick={() => setSel(new Set())} className="ml-auto text-[11px] text-slate-400 hover:text-slate-600">Clear</button>
            )}
          </div>
        )}
        {sel.size > 0 && (
          <div className="px-3 py-2 border-b bg-brand-50 flex items-center gap-2">
            <button onClick={() => setBulkCo(true)} disabled={!!bulkBusy}
              className="flex-1 bg-brand-600 hover:bg-brand-700 text-white text-[11px] font-semibold rounded-lg py-1.5 disabled:opacity-50">
              Set company
            </button>
            {!isAgent && (
              <button onClick={() => { setDelWord(''); setBulkDel(1); }} disabled={!!bulkBusy}
                className="flex-1 border border-red-300 text-red-600 hover:bg-red-50 text-[11px] font-semibold rounded-lg py-1.5 disabled:opacity-50">
                Delete
              </button>
            )}
          </div>
        )}
        {bulkBusy && <div className="px-3 py-1.5 border-b text-[11px] text-amber-700 bg-amber-50">{bulkBusy}</div>}
        <div className="flex-1 overflow-y-auto chat-scroll">
          {shownContacts.map((c) => {
            const name = contactName(c);
            const key = selKey(c);
            return (
              <div key={key} className={`flex items-center border-b ${activeId === key || sel.has(key) ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
                <input type="checkbox" checked={sel.has(key)} onChange={() => toggleSel(key)}
                  aria-label={`Select ${name}`} className="ml-2.5 shrink-0 accent-brand-600 cursor-pointer" />
                <button onClick={() => setActiveId(key)}
                  className="flex-1 min-w-0 text-left px-2.5 py-2.5 flex items-center gap-3">
                  <span className={`w-10 h-10 rounded-full ${avatarColor(name)} text-white flex items-center justify-center text-sm font-bold shrink-0`}>{initials(name)}</span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium text-slate-800 truncate">{name}</span>
                    <span className="block text-xs text-slate-500 truncate">{fmtPhone(primaryPhone(c)) || '—'}</span>
                  </span>
                  <span className="ml-auto flex items-center gap-1 min-w-0 max-w-[55%]">
                    {c.shared && (
                      <span title="Shared contact — from the domain address book, visible to everyone"
                        className="shrink-0 rounded-full bg-brand-100 border border-brand-200 px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-brand-700">
                        SHARED
                      </span>
                    )}
                    {c.company && (
                      <span title={c.company}
                        className="truncate rounded-full bg-slate-100 border border-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                        {c.company}
                      </span>
                    )}
                  </span>
                </button>
              </div>
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
                <div className="text-sm text-slate-500 flex items-center gap-2">
                  {active.company || 'No company'}
                  {active.shared && (
                    <span title="From the domain address book — visible to everyone"
                      className="rounded-full bg-brand-100 border border-brand-200 px-2 py-0.5 text-[10px] font-bold text-brand-700">SHARED</span>
                  )}
                </div>
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
                <button onClick={() => { if (confirm('Delete this contact?')) api.deleteContact(contactId(active), !!active.shared).then(() => { setActiveId(null); reload(); toastSuccess('Contact deleted'); }).catch((e) => toastError(e.message)); }}
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

      {bulkCo && (
        <BulkCompanyModal
          count={sel.size}
          companies={companies}
          busy={bulkBusy}
          onClose={() => { if (!bulkBusy) setBulkCo(false); }}
          onApply={applyBulkCompany}
        />
      )}

      {bulkDel > 0 && (
        <BulkDeleteModal
          step={bulkDel}
          count={sel.size}
          names={selectedContacts.slice(0, 5).map(contactName)}
          word={delWord}
          setWord={setDelWord}
          busy={bulkBusy}
          onClose={() => { if (!bulkBusy) { setBulkDel(0); setDelWord(''); } }}
          onContinue={() => setBulkDel(2)}
          onBack={() => { setDelWord(''); setBulkDel(1); }}
          onConfirm={applyBulkDelete}
        />
      )}
    </div>
  );
}

/** Step: choose an existing company or type a new one (auto-created on apply). */
function BulkCompanyModal({ count, companies, busy, onClose, onApply }) {
  const [pick, setPick] = useState('');
  const [custom, setCustom] = useState('');
  const name = (custom.trim() || pick || '').trim();
  return (
    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-white rounded-xl border shadow-xl w-full max-w-sm p-5" onClick={(e) => e.stopPropagation()}>
        <h3 className="text-base font-bold text-slate-900">Set company on {count} contact{count === 1 ? '' : 's'}</h3>
        <p className="text-xs text-slate-500 mt-1">Choose an existing company or type a new name — a new name is created automatically. Existing contact details are preserved.</p>
        <select value={pick} onChange={(e) => { setPick(e.target.value); if (e.target.value) setCustom(''); }} disabled={!!busy}
          className="mt-3 w-full border rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
          <option value="">— Choose a company —</option>
          {companies.map((c) => <option key={c.id ?? c.name} value={c.name || ''}>{c.name}</option>)}
        </select>
        <input value={custom} onChange={(e) => { setCustom(e.target.value); if (e.target.value) setPick(''); }} disabled={!!busy}
          placeholder="…or type a new company name"
          className="mt-2 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
        {busy && <p className="text-xs text-amber-600 mt-2">{busy}</p>}
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={onClose} disabled={!!busy}
            className="border text-sm rounded-lg px-4 py-2 hover:bg-slate-50 disabled:opacity-50">Cancel</button>
          <button onClick={() => name && onApply(name)} disabled={!name || !!busy}
            className="bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg px-4 py-2 disabled:opacity-50">
            {busy || 'Update company'}
          </button>
        </div>
      </div>
    </div>
  );
}

/**
 * Two-way delete confirmation: step 1 warns and previews, step 2 requires
 * typing DELETE. The server re-checks the word independently.
 */
function BulkDeleteModal({ step, count, names, word, setWord, busy, onClose, onContinue, onBack, onConfirm }) {
  const ready = word.trim() === 'DELETE';
  return (
    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-white rounded-xl border shadow-xl w-full max-w-sm p-5" onClick={(e) => e.stopPropagation()}>
        {step === 1 ? (
          <>
            <h3 className="text-base font-bold text-red-600">Delete {count} contact{count === 1 ? '' : 's'}?</h3>
            <p className="text-xs text-slate-500 mt-1">They will be removed from this app <strong>and</strong> from the Dynalink portal. This cannot be undone.</p>
            <ul className="mt-3 text-xs text-slate-600 bg-slate-50 border rounded-lg p-2.5 space-y-0.5">
              {names.map((n, i) => <li key={i} className="truncate">{n}</li>)}
              {count > names.length && <li className="text-slate-400">…and {count - names.length} more</li>}
            </ul>
            <div className="flex justify-end gap-2 mt-4">
              <button onClick={onClose} className="border text-sm rounded-lg px-4 py-2 hover:bg-slate-50">Cancel</button>
              <button onClick={onContinue} className="bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg px-4 py-2">Continue</button>
            </div>
          </>
        ) : (
          <>
            <h3 className="text-base font-bold text-red-600">Confirm permanent delete</h3>
            <p className="text-xs text-slate-500 mt-1">Type <strong className="font-mono">DELETE</strong> below to permanently remove {count} contact{count === 1 ? '' : 's'}.</p>
            <input value={word} onChange={(e) => setWord(e.target.value)} disabled={!!busy} autoFocus
              placeholder="DELETE"
              className="mt-3 w-full border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-red-400" />
            {busy && <p className="text-xs text-amber-600 mt-2">{busy}</p>}
            <div className="flex justify-end gap-2 mt-4">
              <button onClick={onBack} disabled={!!busy}
                className="border text-sm rounded-lg px-4 py-2 hover:bg-slate-50 disabled:opacity-50">Back</button>
              <button onClick={onConfirm} disabled={!ready || !!busy}
                className="bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg px-4 py-2 disabled:opacity-50">
                {busy || 'Permanently delete'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
