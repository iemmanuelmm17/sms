import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, fmtPhone } from '../../api/client';
import { toastError, toastSuccess } from '../../lib/toast';

const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';
const emptyAdmin = { username: '', first_name: '', last_name: '', password: '', secret_question: '', secret_answer: '' };

export default function TenantDetail() {
  const { id } = useParams();
  const nav = useNavigate();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ domain: '', dynalink_user: '', dynalink_pass: '', company_name: '' });
  const [saving, setSaving] = useState(false);
  const [verified, setVerified] = useState(null);
  const [mainNums, setMainNums] = useState([]);
  const [mainPick, setMainPick] = useState('');
  const [loadingMain, setLoadingMain] = useState(false);
  const [settingMain, setSettingMain] = useState(false);
  const [adminModal, setAdminModal] = useState(null); // {mode:'add'} | {mode:'edit', admin}
  const [adminForm, setAdminForm] = useState(emptyAdmin);
  const [resetModal, setResetModal] = useState(null); // admin
  const [resetForm, setResetForm] = useState({ superadmin_password: '', new_password: '', confirm: '' });
  const [busy, setBusy] = useState(false);
  const [delTenant, setDelTenant] = useState(false);
  const [delPreview, setDelPreview] = useState(null);
  const [delAdmin, setDelAdmin] = useState(null);
  const [delAdminPreview, setDelAdminPreview] = useState(null);
  const [delForm, setDelForm] = useState({ name: '', password: '' });
  const setD = (k, v) => setDelForm((f) => ({ ...f, [k]: v }));
  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const setA = (k, v) => setAdminForm((f) => ({ ...f, [k]: v }));
  const setR = (k, v) => setResetForm((f) => ({ ...f, [k]: v }));

  const load = async () => {
    setLoading(true);
    try {
      const d = await api.superTenant(id);
      setData(d);
      setForm({ domain: d.tenant.domain || '', dynalink_user: d.tenant.dynalink_user || '', dynalink_pass: '', company_name: d.tenant.company_name || '' });
      setVerified(null);
    } catch (e) { toastError(e?.response?.data?.message || 'Failed to load tenant.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); /* eslint-disable-next-line */ }, [id]);

  const save = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = { company_name: form.company_name.trim() || null };
      if (form.dynalink_pass) payload.dynalink_pass = form.dynalink_pass; // blank = keep
      const r = await api.superTenantUpdate(id, payload);
      setData((d) => ({ ...d, tenant: r.tenant }));
      setForm((f) => ({ ...f, dynalink_pass: '' }));
      setVerified(r.verified);
      if (r.verified === false) toastError('Saved — but the Dynalink credential did NOT verify.');
      else toastSuccess('Tenant saved.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setSaving(false); }
  };

  const loadMainNums = async () => {
    setLoadingMain(true);
    try {
      const r = await api.superTenantNumbers(id);
      const list = Array.isArray(r?.numbers) ? r.numbers : [];
      setMainNums(list);
      setMainPick(list[0]?.digits || '');
      if (!list.length) toastError('No assigned SMS numbers on this account.');
    } catch (e) { toastError(e?.response?.data?.message || 'Could not load SMS numbers.'); }
    finally { setLoadingMain(false); }
  };

  const setMainOnce = async () => {
    if (!mainPick) return toastError('Pick a number first.');
    setSettingMain(true);
    try {
      const r = await api.superTenantUpdate(id, { main_number: mainPick });
      setData((d) => ({ ...d, tenant: r.tenant }));
      setMainNums([]); setMainPick('');
      toastSuccess('Main SMS number set — it is now locked.');
    } catch (e) { toastError(e?.response?.data?.message || 'Save failed.'); }
    finally { setSettingMain(false); }
  };

  const flipStatus = async (toActive) => {
    const t = data?.tenant;
    if (!t) return;
    if (!window.confirm(toActive ? `Reactivate @${t.name}? Their logins will work again.` : `Deactivate @${t.name}? Their admins and agents will be locked out with a notice.`)) return;
    try {
      const r = toActive ? await api.superTenantReactivate(id) : await api.superTenantDeactivate(id);
      setData((d) => ({ ...d, tenant: r.tenant }));
      toastSuccess(toActive ? 'Tenant reactivated.' : 'Tenant deactivated.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Failed.'); }
  };

  const openAdd = () => { setAdminForm(emptyAdmin); setAdminModal({ mode: 'add' }); };
  const openEdit = (a) => {
    setAdminForm({ username: a.username, first_name: a.first_name, last_name: a.last_name, password: '', secret_question: a.secret_question || '', secret_answer: '', status: a.status });
    setAdminModal({ mode: 'edit', admin: a });
  };

  const saveAdmin = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      if (adminModal.mode === 'add') {
        await api.superTenantAdminCreate(id, {
          username: adminForm.username.trim(), first_name: adminForm.first_name.trim(),
          last_name: adminForm.last_name.trim(), password: adminForm.password,
          secret_question: adminForm.secret_question.trim(), secret_answer: adminForm.secret_answer,
        });
        toastSuccess('Admin created.');
      } else {
        const payload = { first_name: adminForm.first_name.trim(), last_name: adminForm.last_name.trim(), status: adminForm.status };
        if (adminForm.secret_question.trim()) payload.secret_question = adminForm.secret_question.trim();
        if (adminForm.secret_answer) payload.secret_answer = adminForm.secret_answer;
        await api.superTenantAdminUpdate(id, adminModal.admin.id, payload);
        toastSuccess('Admin updated.');
      }
      setAdminModal(null); load();
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setBusy(false); }
  };

  const doReset = async (e) => {
    e.preventDefault();
    if (resetForm.new_password.length < 8) { toastError('New password needs at least 8 characters.'); return; }
    if (resetForm.new_password !== resetForm.confirm) { toastError('Passwords do not match.'); return; }
    setBusy(true);
    try {
      await api.superTenantAdminPassword(id, resetModal.id, {
        superadmin_password: resetForm.superadmin_password, new_password: resetForm.new_password,
      });
      setResetModal(null); setResetForm({ superadmin_password: '', new_password: '', confirm: '' });
      toastSuccess('Password reset — their sessions were dropped.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Reset failed.'); }
    finally { setBusy(false); }
  };

  const openDelTenant = async () => {
    setDelTenant(true); setDelPreview(null); setDelForm({ name: '', password: '' });
    try { setDelPreview(await api.superTenantDeletePreview(id)); }
    catch (e) { toastError(e?.response?.data?.message || 'Failed to load impact.'); setDelTenant(null); }
  };
  const doDelTenant = async (e) => {
    e.preventDefault(); setBusy(true);
    try {
      await api.superTenantDelete(id, { confirm_name: delForm.name.trim(), superadmin_password: delForm.password });
      toastSuccess('Tenant deleted.');
      nav('/super/tenants', { replace: true });
    } catch (ex) { toastError(ex?.response?.data?.message || 'Delete failed.'); }
    finally { setBusy(false); }
  };
  const openDelAdmin = async (a) => {
    setDelAdmin(a); setDelAdminPreview(null); setDelForm({ name: '', password: '' });
    try { setDelAdminPreview(await api.superTenantAdminDeletePreview(id, a.id)); }
    catch (e) { toastError(e?.response?.data?.message || 'Failed to load impact.'); setDelAdmin(null); }
  };
  const doDelAdmin = async (e) => {
    e.preventDefault(); setBusy(true);
    try {
      await api.superTenantAdminDelete(id, delAdmin.id, { confirm_username: delForm.name.trim(), superadmin_password: delForm.password });
      setDelAdmin(null); toastSuccess('Admin deleted.'); load();
    } catch (ex) { toastError(ex?.response?.data?.message || 'Delete failed.'); }
    finally { setBusy(false); }
  };

  if (loading) return <div className="text-sm text-slate-500">Loading…</div>;
  if (!data) return <div className="text-sm text-slate-500">Tenant not found. <Link to="/super/tenants" className="text-brand-600 hover:underline">← Back</Link></div>;
  const t = data.tenant;

  return (
    <div>
      <Link to="/super/tenants" className="text-xs text-brand-600 hover:underline">← All tenants</Link>
      <div className="flex items-center justify-between mt-1 mb-4">
        <h1 className="text-xl font-bold text-slate-900">@{t.name}
          <span className="ml-2 text-sm font-normal text-slate-400">{t.company_name}</span></h1>
        {t.status === 'active'
          ? <span className="text-xs font-medium text-emerald-700 bg-emerald-50 rounded-full px-2 py-0.5">Active</span>
          : <span className="text-xs font-medium text-slate-500 bg-slate-100 rounded-full px-2 py-0.5">Deactivated</span>}
      </div>

      <form onSubmit={save} className="bg-white border rounded-xl p-5 mb-4">
        <h2 className="text-sm font-bold text-slate-800 mb-3">Dynalink identity & company</h2>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="text-xs font-medium text-slate-600">Domain <span className="text-slate-400">(locked)</span></label>
            <input value={form.domain} disabled className={input + ' bg-slate-50 text-slate-500'} /></div>
          <div><label className="text-xs font-medium text-slate-600">Dynalink username <span className="text-slate-400">(locked)</span></label>
            <input value={form.dynalink_user} disabled className={input + ' bg-slate-50 text-slate-500'} /></div>
          <div><label className="text-xs font-medium text-slate-600">Dynalink password <span className="text-slate-400">(blank = keep)</span></label>
            <input type="password" value={form.dynalink_pass} onChange={(e) => set('dynalink_pass', e.target.value)} placeholder="••••••••" className={input} /></div>
          <div><label className="text-xs font-medium text-slate-600">Company name</label>
            <input value={form.company_name} onChange={(e) => set('company_name', e.target.value)} className={input} /></div>
          <div className="col-span-2 border-t pt-3 mt-1">
            <label className="text-xs font-medium text-slate-600">Main SMS number {t.main_number ? <span className="text-slate-400">(locked)</span> : <span className="text-amber-600">(not set — one-time)</span>}</label>
            {t.main_number ? (
              <input value={fmtPhone(t.main_number)} disabled className={input + ' bg-slate-50 text-slate-500'} />
            ) : mainNums.length === 0 ? (
              <div><button type="button" onClick={loadMainNums} disabled={loadingMain}
                className="mt-1 text-sm border rounded-lg px-4 py-2 hover:bg-slate-50 disabled:opacity-50">
                {loadingMain ? 'Loading…' : 'Load assigned numbers'}</button></div>
            ) : (
              <div className="mt-1 border rounded-lg p-2 space-y-1 max-h-36 overflow-y-auto bg-white">
                {mainNums.map((n) => (
                  <label key={n.digits} className="flex items-center gap-2 text-sm text-slate-700">
                    <input type="radio" name="main-pick" checked={mainPick === n.digits} onChange={() => setMainPick(n.digits)} />
                    {fmtPhone(n.number)}
                  </label>
                ))}
                <button type="button" onClick={setMainOnce} disabled={settingMain || !mainPick}
                  className="mt-1 text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
                  {settingMain ? 'Setting…' : 'Set main number (locks forever)'}</button>
              </div>
            )}
          </div>
        </div>
        {verified !== null && (
          <div className={`text-xs rounded-lg p-2 mt-3 ${verified ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-600'}`}>
            {verified ? '✓ Dynalink credential verified live.' : '✕ Dynalink credential did NOT verify — logins will fail until fixed.'}
          </div>
        )}
        <div className="flex gap-2 justify-end mt-4">
          <button type="button" onClick={openDelTenant}
            className="text-sm px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white">Delete…</button>
          {t.status === 'active' ? (
            <button type="button" onClick={() => flipStatus(false)}
              className="text-sm px-4 py-2 rounded-lg border border-red-200 text-red-600 hover:bg-red-50">Deactivate tenant</button>
          ) : (
            <button type="button" onClick={() => flipStatus(true)}
              className="text-sm px-4 py-2 rounded-lg border border-emerald-200 text-emerald-700 hover:bg-emerald-50">Reactivate tenant</button>
          )}
          <button disabled={saving}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
            {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>
      </form>

      <div className="flex items-center justify-between mb-2">
        <h2 className="text-sm font-bold text-slate-800">Tenant admins ({data.admins?.length || 0})</h2>
        <button onClick={openAdd} className="text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5 font-semibold">+ Add admin</button>
      </div>
      <div className="bg-white border rounded-xl overflow-hidden mb-4">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-xs text-slate-400 border-b">
            <th className="px-4 py-2">Username</th><th className="px-4 py-2">Name</th>
            <th className="px-4 py-2">Status</th><th className="px-4 py-2">Last seen</th><th className="px-4 py-2"></th>
          </tr></thead>
          <tbody>
            {(data.admins || []).map((a) => (
              <tr key={a.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-4 py-2.5 font-medium text-slate-900">{a.username}@{t.name}</td>
                <td className="px-4 py-2.5 text-slate-600">{a.first_name} {a.last_name}</td>
                <td className="px-4 py-2.5">{a.status === 'active'
                  ? <span className="text-xs text-emerald-700">Active</span>
                  : <span className="text-xs text-slate-500">Deactivated</span>}</td>
                <td className="px-4 py-2.5 text-xs text-slate-400">{a.last_seen_at ? new Date(a.last_seen_at).toLocaleString() : '—'}</td>
                <td className="px-4 py-2.5 text-right whitespace-nowrap">
                  <button onClick={() => openEdit(a)} className="text-xs text-brand-600 hover:underline font-medium mr-3">Edit</button>
                  <button onClick={() => setResetModal(a)} className="text-xs text-amber-600 hover:underline font-medium">Reset password</button>
                  <button onClick={() => openDelAdmin(a)} className="text-xs text-red-600 hover:underline font-medium ml-3">Delete</button>
                </td>
              </tr>
            ))}
            {(data.admins || []).length === 0 && (
              <tr><td colSpan={5} className="px-4 py-4 text-center text-xs text-slate-400">No admins — nobody can log in to this tenant yet.</td></tr>
            )}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-slate-400">Agents on this tenant: <strong>{data.agents_count ?? 0}</strong> (managed by the tenant admin).</p>

      {adminModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" onClick={() => setAdminModal(null)}>
          <form onSubmit={saveAdmin} onClick={(e) => e.stopPropagation()}
            className="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 max-h-[90vh] overflow-y-auto">
            <h2 className="text-lg font-bold text-slate-900 mb-4">{adminModal.mode === 'add' ? 'Add admin' : `Edit ${adminModal.admin.username}`}</h2>
            <div className="grid grid-cols-2 gap-3">
              {adminModal.mode === 'add' ? (
                <>
                  <div><label className="text-xs font-medium text-slate-600">Username *</label>
                    <input value={adminForm.username} onChange={(e) => setA('username', e.target.value)} className={input} /></div>
                  <div><label className="text-xs font-medium text-slate-600">Password (min 8) *</label>
                    <input type="password" value={adminForm.password} onChange={(e) => setA('password', e.target.value)} className={input} /></div>
                </>
              ) : (
                <div className="col-span-2"><label className="text-xs font-medium text-slate-600">Status</label>
                  <select value={adminForm.status} onChange={(e) => setA('status', e.target.value)} className={input}>
                    <option value="active">Active</option>
                    <option value="deactivated">Deactivated</option>
                  </select></div>
              )}
              <div><label className="text-xs font-medium text-slate-600">First name *</label>
                <input value={adminForm.first_name} onChange={(e) => setA('first_name', e.target.value)} className={input} /></div>
              <div><label className="text-xs font-medium text-slate-600">Last name *</label>
                <input value={adminForm.last_name} onChange={(e) => setA('last_name', e.target.value)} className={input} /></div>
              <div className="col-span-2"><label className="text-xs font-medium text-slate-600">Secret question {adminModal.mode === 'add' ? '*' : <span className="text-slate-400">(blank = keep)</span>}</label>
                <input value={adminForm.secret_question} onChange={(e) => setA('secret_question', e.target.value)} className={input} /></div>
              <div className="col-span-2"><label className="text-xs font-medium text-slate-600">Secret answer {adminModal.mode === 'add' ? '*' : <span className="text-slate-400">(blank = keep)</span>}</label>
                <input value={adminForm.secret_answer} onChange={(e) => setA('secret_answer', e.target.value)} className={input} /></div>
            </div>
            <div className="flex gap-2 justify-end mt-5">
              <button type="button" onClick={() => setAdminModal(null)} className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
              <button disabled={busy} className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
                {busy ? 'Saving…' : adminModal.mode === 'add' ? 'Create admin' : 'Save'}
              </button>
            </div>
          </form>
        </div>
      )}

      {resetModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" onClick={() => setResetModal(null)}>
          <form onSubmit={doReset} onClick={(e) => e.stopPropagation()}
            className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
            <h2 className="text-lg font-bold text-slate-900 mb-1">Reset password</h2>
            <p className="text-xs text-slate-500 mb-4">{resetModal.username}@{t.name} — their sessions will drop.</p>
            <div className="space-y-3">
              <div><label className="text-xs font-medium text-slate-600">Your superadmin password *</label>
                <input type="password" value={resetForm.superadmin_password} onChange={(e) => setR('superadmin_password', e.target.value)} className={input} /></div>
              <div><label className="text-xs font-medium text-slate-600">New password (min 8) *</label>
                <input type="password" value={resetForm.new_password} onChange={(e) => setR('new_password', e.target.value)} className={input} /></div>
              <div><label className="text-xs font-medium text-slate-600">Confirm *</label>
                <input type="password" value={resetForm.confirm} onChange={(e) => setR('confirm', e.target.value)} className={input} /></div>
            </div>
            <div className="flex gap-2 justify-end mt-5">
              <button type="button" onClick={() => setResetModal(null)} className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
              <button disabled={busy} className="text-sm bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
                {busy ? 'Resetting…' : 'Reset password'}
              </button>
            </div>
          </form>
        </div>
      )}
      {delTenant && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" onClick={() => setDelTenant(false)}>
          <div onClick={(e) => e.stopPropagation()} className="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 max-h-[90vh] overflow-y-auto">
            <h2 className="text-lg font-bold text-red-600 mb-1">Delete @{t.name}?</h2>
            <p className="text-xs text-slate-500 mb-3">Permanent. Remote Dynalink messages are untouched; everything below is destroyed. Audit history is kept.</p>
            {!delPreview ? <div className="text-sm text-slate-500 mb-3">Loading impact…</div> : (
              <ul className="text-xs bg-slate-50 border rounded-lg p-3 space-y-1 mb-3">
                {Object.entries(delPreview.counts).map(([k, v]) => (
                  <li key={k} className="flex justify-between"><span className="text-slate-500">{k.replace(/_/g, ' ')}</span><strong>{v}</strong></li>
                ))}
              </ul>
            )}
            <form onSubmit={doDelTenant} className="space-y-3">
              <div><label className="text-xs font-medium text-slate-600">Type <strong>{t.name}</strong> to confirm</label>
                <input value={delForm.name} onChange={(e) => setD('name', e.target.value)} className={input} /></div>
              <div><label className="text-xs font-medium text-slate-600">Your superadmin password</label>
                <input type="password" value={delForm.password} onChange={(e) => setD('password', e.target.value)} className={input} /></div>
              <div className="flex gap-2 justify-end">
                <button type="button" onClick={() => setDelTenant(false)} className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
                <button disabled={busy || delForm.name.trim().toLowerCase() !== t.name}
                  className="text-sm bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
                  {busy ? 'Deleting…' : 'Delete forever'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {delAdmin && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" onClick={() => setDelAdmin(null)}>
          <div onClick={(e) => e.stopPropagation()} className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
            <h2 className="text-lg font-bold text-red-600 mb-1">Delete {delAdmin.username}?</h2>
            {delAdminPreview?.is_last_admin && (
              <div className="text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-2.5 mb-3">
                ⚠️ Last admin on this tenant — nobody will be able to log in until you add another.
              </div>
            )}
            <form onSubmit={doDelAdmin} className="space-y-3">
              <div><label className="text-xs font-medium text-slate-600">Type <strong>{delAdmin.username}</strong> to confirm</label>
                <input value={delForm.name} onChange={(e) => setD('name', e.target.value)} className={input} /></div>
              <div><label className="text-xs font-medium text-slate-600">Your superadmin password</label>
                <input type="password" value={delForm.password} onChange={(e) => setD('password', e.target.value)} className={input} /></div>
              <div className="flex gap-2 justify-end">
                <button type="button" onClick={() => setDelAdmin(null)} className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
                <button disabled={busy || delForm.name.trim().toLowerCase() !== delAdmin.username.toLowerCase()}
                  className="text-sm bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
                  {busy ? 'Deleting…' : 'Delete admin'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
