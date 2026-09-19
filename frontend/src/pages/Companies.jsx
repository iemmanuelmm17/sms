import { useEffect, useState } from 'react';
import { api, contactName, fmtPhone, primaryPhone, contactId, fmtDateTime } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import Modal from '../components/Modal';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';
import { useSearchParams } from 'react-router-dom';

const digits = (v) => String(v ?? '').replace(/\D/g, '');
const parseNums = (t) => String(t || '').split(/[\n,;]+/).map((s) => s.trim()).filter(Boolean);

export default function Companies() {
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const [tab, setTab] = useState('companies'); // 'companies' | 'groups'
  const [searchParams, setSearchParams] = useSearchParams();
  // Sidebar Groups deep-link (?tab=groups); tab clicks write back.
  const goTab = (t) => {
    setTab(t);
    setSearchParams(t === 'groups' ? { tab: 'groups' } : {}, { replace: true });
  };
  useEffect(() => {
    const t = searchParams.get('tab');
    if (t === 'groups' || t === 'companies') setTab(t);
  }, [searchParams]);
  const [companies, setCompanies] = useState([]);
  const [groups, setGroups] = useState([]);
  const [contacts, setContacts] = useState([]);
  const [numbers, setNumbers] = useState([]);
  const [activeCompanyId, setActiveCompanyId] = useState(null);
  const [activeGroupId, setActiveGroupId] = useState(null);
  const [editingCompany, setEditingCompany] = useState(null);
  const [editingGroup, setEditingGroup] = useState(null);
  const { lastSync } = useSocket();

  const reloadCompanies = () => api.companies().then(setCompanies).catch(() => {});
  const reloadGroups = () => api.groups().then(setGroups).catch(() => {});
  const reloadContacts = () => api.contacts().then(setContacts).catch(() => {});

  useEffect(() => {
    reloadCompanies(); reloadGroups(); reloadContacts();
    api.smsNumbers().then(setNumbers).catch(() => {});
  }, []);

  // Another instance changed companies / groups / contacts → refresh.
  useEffect(() => {
    if (!lastSync) return;
    if (lastSync.resource === 'companies') reloadCompanies();
    if (lastSync.resource === 'groups') reloadGroups();
    if (lastSync.resource === 'contacts') reloadContacts();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  // A company CONTAINS (by convention): contacts whose company field matches
  // its name, its stored number list, and groups linked via company_id.
  const companyContacts = (name) => contacts.filter((c) => String(c.company || '').toLowerCase() === String(name || '').toLowerCase());
  const companyGroups = (id) => groups.filter((g) => (g.company_id || '') === id);
  const companyName = (id) => companies.find((c) => c.id === id)?.name || '';
  const activeCompany = companies.find((c) => c.id === activeCompanyId) || null;
  const activeGroup = groups.find((g) => g.id === activeGroupId) || null;

  const deleteCompany = async (c) => {
    if (!confirm(`Delete company "${c.name}"?`)) return;
    try {
      await api.deleteCompany(c.id);
      setActiveCompanyId(null); reloadCompanies(); toastSuccess('Company deleted');
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };

  const deleteGroup = async (g) => {
    if (!confirm(`Delete group "${g.name}"? Contacts stay untouched — only this group is removed.`)) return;
    try {
      await api.deleteGroup(g.id);
      setActiveGroupId(null); reloadGroups(); toastSuccess('Group deleted');
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };

  const tabCls = (t) => `px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px ${tab === t ? 'border-brand-600 text-slate-900' : 'border-transparent text-slate-400 hover:text-slate-600'}`;

  return (
    <div className="h-full flex flex-col min-h-0">
      <div className="bg-white border-b px-3 flex gap-1 shrink-0">
        <button onClick={() => goTab('companies')} className={tabCls('companies')}>🏢 Companies ({companies.length})</button>
        <button onClick={() => goTab('groups')} className={tabCls('groups')}>📁 Groups ({groups.length})</button>
      </div>

      {tab === 'companies' ? (
        <div className="flex-1 flex flex-col md:flex-row min-h-0">
          <div className="w-full md:w-80 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
            <div className="p-3 border-b">
              <button onClick={() => setEditingCompany({ name: '', address: '', note: '', numbers: [] })}
                className="w-full bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg py-2">+ New Company</button>
            </div>
            <div className="flex-1 overflow-y-auto chat-scroll">
              {companies.map((c) => {
                const cc = companyContacts(c.name).length;
                const cg = companyGroups(c.id).length;
                const nn = (c.numbers || []).length;
                return (
                  <button key={c.id} onClick={() => setActiveCompanyId(c.id)}
                    className={`w-full text-left px-3 py-2.5 border-b ${activeCompanyId === c.id ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
                    <div className="text-sm font-semibold text-slate-800">🏢 {c.name}</div>
                    <div className="text-xs text-slate-500">{cc} contact(s) • {nn} number(s) • {cg} group(s)</div>
                  </button>
                );
              })}
              {companies.length === 0 && <div className="p-6 text-sm text-slate-400 text-center">No companies yet.</div>}
            </div>
          </div>
          <div className="flex-1 bg-slate-50 p-4 md:p-6 overflow-y-auto chat-bg">
            {!activeCompany ? <div className="text-sm text-slate-400">Select a company.</div> : (
              <div className="bg-white rounded-xl border p-6 max-w-xl">
                <h2 className="text-xl font-bold">🏢 {activeCompany.name}</h2>
                {activeCompany.created_at && <div className="text-[11px] text-slate-400 mt-1">Created by {activeCompany.created_by_name || activeCompany.created_by || 'System'}{` • ${fmtDateTime(activeCompany.created_at)}`}{activeCompany.updated_at && activeCompany.updated_at !== activeCompany.created_at ? ` • Updated by ${activeCompany.updated_by_name || activeCompany.updated_by || 'System'} • ${fmtDateTime(activeCompany.updated_at)}` : ''}</div>}
                {activeCompany.address && <p className="text-sm text-slate-600 mt-1">📍 {activeCompany.address}</p>}
                {activeCompany.note && <p className="text-sm text-slate-600 mt-2 whitespace-pre-wrap">📝 {activeCompany.note}</p>}

                <h3 className="text-xs font-semibold text-slate-500 mt-4 mb-1">Numbers ({(activeCompany.numbers || []).length})</h3>
                {(activeCompany.numbers || []).length === 0
                  ? <p className="text-xs text-slate-400">No numbers.</p>
                  : (
                    <div className="border rounded-lg divide-y max-h-32 overflow-y-auto chat-scroll">
                      {activeCompany.numbers.map((n, i) => <div key={i} className="px-3 py-1.5 text-sm">{fmtPhone(n)}</div>)}
                    </div>
                  )}

                <h3 className="text-xs font-semibold text-slate-500 mt-4 mb-1">Groups ({companyGroups(activeCompany.id).length})</h3>
                {companyGroups(activeCompany.id).length === 0
                  ? <p className="text-xs text-slate-400">No linked groups.</p>
                  : (
                    <div className="border rounded-lg divide-y max-h-32 overflow-y-auto chat-scroll">
                      {companyGroups(activeCompany.id).map((g) => (
                        <button key={g.id} onClick={() => { goTab('groups'); setActiveGroupId(g.id); }}
                          className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50">📁 {g.name} <span className="text-slate-400 text-xs">• {g.members?.length || 0} member(s)</span></button>
                      ))}
                    </div>
                  )}

                <h3 className="text-xs font-semibold text-slate-500 mt-4 mb-1">Contacts ({companyContacts(activeCompany.name).length})</h3>
                {companyContacts(activeCompany.name).length === 0
                  ? <p className="text-xs text-slate-400">No contacts assigned — set the Company field on a contact.</p>
                  : (
                    <div className="border rounded-lg divide-y max-h-40 overflow-y-auto chat-scroll">
                      {companyContacts(activeCompany.name).map((c) => (
                        <div key={contactId(c) || primaryPhone(c)} className="px-3 py-1.5 text-sm flex justify-between">
                          <span>{contactName(c)}</span>
                          <span className="text-slate-400 text-xs">{fmtPhone(primaryPhone(c))}</span>
                        </div>
                      ))}
                    </div>
                  )}

                <div className="flex gap-2 mt-4">
                  <button onClick={() => setEditingCompany({ ...activeCompany })}
                    className="bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg px-4 py-2">Edit</button>
                  {!isAgent && (
                    <button onClick={() => deleteCompany(activeCompany)}
                      className="border border-red-200 text-red-600 text-sm rounded-lg px-4 py-2 hover:bg-red-50">Delete</button>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      ) : (
        <div className="flex-1 flex flex-col md:flex-row min-h-0">
          <div className="w-full md:w-80 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
            <div className="p-3 border-b">
              <button onClick={() => setEditingGroup({ name: '', description: '', company_id: '', memberIds: [], manualNumbers: [] })}
                className="w-full bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg py-2">+ New Group</button>
            </div>
            <div className="flex-1 overflow-y-auto chat-scroll">
              {groups.map((g) => (
                <button key={g.id} onClick={() => setActiveGroupId(g.id)}
                  className={`w-full text-left px-3 py-2.5 border-b ${activeGroupId === g.id ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
                  <div className="text-sm font-semibold text-slate-800">📁 {g.name}</div>
                  <div className="text-xs text-slate-500">{g.members?.length || 0} member(s){g.company_id && companyName(g.company_id) ? ` • 🏢 ${companyName(g.company_id)}` : ''}{g.description ? ` • ${g.description}` : ''}</div>
                </button>
              ))}
              {groups.length === 0 && <div className="p-6 text-sm text-slate-400 text-center">No groups yet.</div>}
            </div>
          </div>
          <div className="flex-1 bg-slate-50 p-4 md:p-6 overflow-y-auto chat-bg">
            {!activeGroup ? <div className="text-sm text-slate-400">Select a group.</div> : (
              <div className="bg-white rounded-xl border p-6 max-w-xl">
                <h2 className="text-xl font-bold">📁 {activeGroup.name}</h2>
                {activeGroup.created_at && <div className="text-[11px] text-slate-400 mt-1">Created by {activeGroup.created_by_name || activeGroup.created_by || 'System'}{` • ${fmtDateTime(activeGroup.created_at)}`}{activeGroup.updated_at && activeGroup.updated_at !== activeGroup.created_at ? ` • Updated by ${activeGroup.updated_by_name || activeGroup.updated_by || 'System'} • ${fmtDateTime(activeGroup.updated_at)}` : ''}</div>}
                <p className="text-xs text-slate-400 mb-3">
                  {activeGroup.members?.length || 0} members
                  {activeGroup.company_id ? (companyName(activeGroup.company_id) ? ` • 🏢 ${companyName(activeGroup.company_id)}` : ' • 🏢 (company removed)') : ' • No company'}
                </p>
                {activeGroup.description && <p className="text-sm text-slate-600 mb-3">{activeGroup.description}</p>}
                <div className="border rounded-lg divide-y max-h-72 overflow-y-auto chat-scroll">
                  {(activeGroup.members || []).map((m, i) => (
                    <div key={i} className="px-3 py-2 text-sm flex justify-between">
                      <span>{`${m['name-first-name'] || ''} ${m['name-last-name'] || ''}`.trim() || '—'}</span>
                      <span className="text-slate-400 text-xs">{fmtPhone(m.phone)}</span>
                    </div>
                  ))}
                </div>
                <div className="flex gap-2 mt-4">
                  <button
                    onClick={() => setEditingGroup({
                      ...activeGroup,
                      memberIds: (activeGroup.members || []).map((m) => m['unique-id']).filter(Boolean),
                      manualNumbers: (activeGroup.members || []).filter((m) => !m['unique-id']).map((m) => m.phone).filter(Boolean),
                    })}
                    className="bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg px-4 py-2">Edit</button>
                  {!isAgent && (
                    <button onClick={() => deleteGroup(activeGroup)}
                      className="border border-red-200 text-red-600 text-sm rounded-lg px-4 py-2 hover:bg-red-50">Delete</button>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {editingCompany && (
        <CompanyForm initial={editingCompany} linkedGroups={editingCompany.id ? companyGroups(editingCompany.id) : []}
          onClose={() => setEditingCompany(null)}
          onSaved={() => { setEditingCompany(null); reloadCompanies(); toastSuccess('Company saved'); }} />
      )}
      {editingGroup && (
        <GroupForm initial={editingGroup} contacts={contacts} companies={companies}
          onClose={() => setEditingGroup(null)}
          onSaved={() => { setEditingGroup(null); reloadGroups(); toastSuccess('Group saved'); }} />
      )}
    </div>
  );
}

function CompanyForm({ initial, linkedGroups, onClose, onSaved }) {
  const [name, setName] = useState(initial.name || '');
  const [address, setAddress] = useState(initial.address || '');
  const [note, setNote] = useState(initial.note || '');
  const [manual, setManual] = useState(() => (initial.numbers || []).join('\n'));
  const [busy, setBusy] = useState(false);
  const isNew = !initial.id;


  const save = async () => {
    if (!name.trim()) return toastError('Company name is required.');
    const nums = [...new Set(parseNums(manual))];
    const bad = nums.find((n) => digits(n).length < 7);
    if (bad) return toastError(`Invalid number "${bad}" — at least 7 digits required.`);
    setBusy(true);
    try {
      const payload = { name: name.trim(), address: address.trim(), note: note.trim(), numbers: nums };
      if (isNew) await api.createCompany(payload);
      else await api.updateCompany(initial.id, payload);
      onSaved();
    } catch (e) { toastError('Save failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  return (
    <Modal onClose={onClose} wide="max-w-md">
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-lg font-bold">{isNew ? 'New Company' : 'Edit Company'}</h2>
        <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
      </div>
      <label className="text-xs font-medium">Company name *</label>
      <input value={name} onChange={(e) => setName(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" />
      <label className="text-xs font-medium">Address <span className="text-slate-400 font-normal">(optional)</span></label>
      <input value={address} onChange={(e) => setAddress(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" />
      <label className="text-xs font-medium">Note <span className="text-slate-400 font-normal">(optional)</span></label>
      <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" />
      <div className="text-xs font-medium mt-1 mb-1">Linked groups {(linkedGroups || []).length > 0 && <span className="text-slate-400 font-normal">({linkedGroups.length})</span>}</div>
      {(linkedGroups || []).length === 0
        ? <div className="text-xs text-slate-400 mb-2">{isNew ? 'Save the company first — then link groups from the Groups tab.' : 'No linked groups. Link one from the Groups tab (edit group → company).'}</div>
        : <div className="border rounded-lg mb-2 max-h-28 overflow-y-auto chat-scroll divide-y">
          {(linkedGroups || []).map((g) => <div key={g.id} className="px-3 py-1.5 text-xs">📁 {g.name} <span className="text-slate-400">• {(g.members || []).length} member(s)</span></div>)}
        </div>}
      <label className="text-xs font-medium">Numbers <span className="text-slate-400 font-normal">(comma or line separated)</span></label>
      <textarea value={manual} onChange={(e) => setManual(e.target.value)} rows={2} placeholder="19175551212, 17185550101"
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-1" />
      <button onClick={save} disabled={busy} className="mt-3 w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
        {busy ? 'Saving…' : 'Save Company'}
      </button>
    </Modal>
  );
}

function GroupForm({ initial, contacts, companies, onClose, onSaved }) {
  const [name, setName] = useState(initial.name || '');
  const [desc, setDesc] = useState(initial.description || '');
  const [companyId, setCompanyId] = useState(initial.company_id || '');
  const [sel, setSel] = useState(initial.memberIds || []);
  const [manual, setManual] = useState((initial.manualNumbers || []).join('\n'));
  const [q, setQ] = useState('');
  const [busy, setBusy] = useState(false);
  const isNew = !initial.id;

  const toggle = (id) => setSel((s) => s.includes(id) ? s.filter((x) => x !== id) : [...s, id]);

  const save = async () => {
    if (!name.trim()) return toastError('Group name is required.');
    const members = sel.map((id) => {
      const c = contacts.find((x) => contactId(x) === id);
      return { 'unique-id': id, 'name-first-name': c?.['name-first-name'] || '', 'name-last-name': c?.['name-last-name'] || '', company: c?.company || '', phone: primaryPhone(c) };
    }).filter((m) => m.phone);
    const manualMembers = parseNums(manual).map((p) => ({ phone: p }));
    const bad = manualMembers.find((m) => digits(m.phone).length < 10);
    if (bad) return toastError(`Invalid number "${bad.phone}" — group numbers need at least 10 digits.`);
    const all = [...members, ...manualMembers];
    if (!all.length) return toastError('Select at least one contact or number.');
    setBusy(true);
    try {
      const payload = { name, description: desc, company_id: companyId || null, members: all };
      if (isNew) await api.createGroup(payload);
      else await api.updateGroup(initial.id, payload);
      onSaved();
    } catch (e) { toastError('Save failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  return (
    <Modal onClose={onClose} wide="max-w-md">
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-lg font-bold">{isNew ? 'New Group' : 'Edit Group'}</h2>
        <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
      </div>
      <label className="text-xs font-medium">Group name *</label>
      <input value={name} onChange={(e) => setName(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" />
      <label className="text-xs font-medium">Company <span className="text-slate-400 font-normal">(optional)</span></label>
      <select value={companyId} onChange={(e) => setCompanyId(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2">
        <option value="">No company</option>
        {(companies || []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
      </select>
      <label className="text-xs font-medium">Description</label>
      <input value={desc} onChange={(e) => setDesc(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" />
      <label className="text-xs font-medium">Members — pick from contacts ({sel.length})</label>
      <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search contacts…" className="w-full border rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-1" />
      <div className="border rounded-lg max-h-44 overflow-y-auto chat-scroll">
        {contacts.filter((c) => (primaryPhone(c) || '').replace(/\D/g, '').length >= 10 && `${contactName(c)} ${c.company || ''}`.toLowerCase().includes(q.toLowerCase())).map((c) => (
          <label key={contactId(c)} className="flex items-center gap-2 px-2 py-1.5 text-xs hover:bg-slate-50 border-b">
            <input type="checkbox" checked={sel.includes(contactId(c))} onChange={() => toggle(contactId(c))} />
            <span className="flex-1">{contactName(c)} <span className="text-slate-400">• {c.company || fmtPhone(primaryPhone(c))}</span></span>
          </label>
        ))}
      </div>
      <label className="text-xs font-medium mt-2 block">Members — manual numbers <span className="text-slate-400 font-normal">(comma or line separated, 10+ digits)</span></label>
      <textarea value={manual} onChange={(e) => setManual(e.target.value)} rows={2} placeholder="19175551212, 17185550101"
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-1" />
      <button onClick={save} disabled={busy} className="mt-3 w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
        {busy ? 'Saving…' : 'Save Group'}
      </button>
    </Modal>
  );
}
