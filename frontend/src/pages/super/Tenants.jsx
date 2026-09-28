import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, fmtPhone } from '../../api/client';
import { toastError, toastSuccess } from '../../lib/toast';

const emptyForm = {
  name: '', domain: '', dynalink_user: '', dynalink_pass: '', company_name: '', main: '',
  withAdmin: true, a_username: '', a_first: '', a_last: '', a_password: '', a_q: '', a_a: '',
};
// Step-1 fingerprint: going back + editing creds invalidates the loaded numbers.
const fpOf = (f) => JSON.stringify([f.name.trim().toLowerCase(), f.domain.trim(), f.dynalink_user.trim(), f.dynalink_pass]);

export default function Tenants() {
  const [tenants, setTenants] = useState([]);
  const [loading, setLoading] = useState(true);
  const [show, setShow] = useState(false);
  const [step, setStep] = useState(1);
  const [form, setForm] = useState(emptyForm);
  const [numbers, setNumbers] = useState([]);
  const [numQ, setNumQ] = useState('');   // domain inventory can be large
  const [loadedKey, setLoadedKey] = useState('');
  const [loadingNums, setLoadingNums] = useState(false);
  const [saving, setSaving] = useState(false);
  const nav = useNavigate();
  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  const load = async () => {
    setLoading(true);
    try { setTenants(await api.superTenants()); }
    catch (e) { toastError(e?.response?.data?.message || 'Failed to load tenants.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const close = () => {
    setShow(false); setStep(1); setForm(emptyForm); setNumbers([]); setLoadedKey(''); setNumQ('');
  };

  const step1Valid = () => {
    if (!/^[a-z0-9]{2,60}$/.test(form.name.trim().toLowerCase())) {
      toastError('Tenant name: 2-60 letters and numbers.'); return false;
    }
    if (!form.domain.trim() || !form.dynalink_user.trim() || !form.dynalink_pass) {
      toastError('Domain, username, and password are required.'); return false;
    }
    return true;
  };

  const loadNumbers = async () => {
    if (!step1Valid()) return;
    setLoadingNums(true);
    try {
      const r = await api.superTenantVerifyNumbers({
        name: form.name.trim().toLowerCase(), domain: form.domain.trim(),
        dynalink_user: form.dynalink_user.trim(), dynalink_pass: form.dynalink_pass,
      });
      const list = Array.isArray(r?.numbers) ? r.numbers : [];
      setNumbers(list);
      // The verified Dynalink identity becomes the tenant admin, so seed the
      // admin username from it. Still editable on step 3.
      setForm((f) => ({
        ...f,
        main: list[0]?.digits || '',
        a_username: f.a_username || f.dynalink_user.trim().toLowerCase(),
      }));
      setLoadedKey(fpOf(form));
      setStep(2);
      toastSuccess(`${list.length} SMS number${list.length === 1 ? '' : 's'} found on this domain.`);
    } catch (ex) { toastError(ex?.response?.data?.message || 'Could not load SMS numbers.'); }
    finally { setLoadingNums(false); }
  };

  const fresh = numbers.length > 0 && loadedKey === fpOf(form);
  const shownNumbers = !numQ.trim() ? numbers : numbers.filter((n) => {
    const t = numQ.trim().toLowerCase();
    const nq = t.replace(/\D/g, '');
    return (nq && String(n.digits).includes(nq))
      || fmtPhone(n.number).toLowerCase().includes(t)
      || String(n.dest || '').toLowerCase().includes(t);
  });

  const create = async (e) => {
    e.preventDefault();
    if (!fresh) return toastError('Reload SMS numbers first — the account changed.');
    if (!form.main) return toastError('Pick the main SMS number.');
    setSaving(true);
    try {
      const payload = {
        name: form.name.trim().toLowerCase(), domain: form.domain.trim(),
        dynalink_user: form.dynalink_user.trim(), dynalink_pass: form.dynalink_pass,
        company_name: form.company_name.trim() || null, main_number: form.main,
      };
      if (form.withAdmin) {
        payload.admin = {
          username: form.a_username.trim(), first_name: form.a_first.trim(),
          last_name: form.a_last.trim(), password: form.a_password,
          secret_question: form.a_q.trim(), secret_answer: form.a_a,
        };
      }
      await api.superTenantCreate(payload);
      close(); load();
      toastSuccess('Tenant created — Dynalink credential verified.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Create failed.'); }
    finally { setSaving(false); }
  };

  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';
  const steps = ['Account', 'Main number', 'Admin'];

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-xl font-bold text-slate-900">Tenants</h1>
        <button onClick={() => setShow(true)}
          className="text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-4 py-2 font-semibold">
          + New tenant
        </button>
      </div>
      {loading ? <div className="text-sm text-slate-500">Loading…</div> : tenants.length === 0 ? (
        <div className="text-sm text-slate-500 bg-white border rounded-xl p-6 text-center">
          No tenants yet — create the first one.
        </div>
      ) : (
        <div className="bg-white border rounded-xl overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-slate-400 border-b">
                <th className="px-4 py-2">Tenant</th>
                <th className="px-4 py-2">Dynalink identity</th>
                <th className="px-4 py-2">Admins</th>
                <th className="px-4 py-2">Agents</th>
                <th className="px-4 py-2">Status</th>
                <th className="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody>
              {tenants.map((t) => (
                <tr key={t.id} className="border-b last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-2.5">
                    <div className="font-medium text-slate-900">@{t.name}</div>
                    <div className="text-xs text-slate-400">{t.company_name || '—'}</div>
                  </td>
                  <td className="px-4 py-2.5 text-slate-600">{t.dynalink_user}@{t.domain}</td>
                  <td className="px-4 py-2.5">{t.admins_count ?? '—'}</td>
                  <td className="px-4 py-2.5">{t.agents_count ?? '—'}</td>
                  <td className="px-4 py-2.5">
                    {t.status === 'active'
                      ? <span className="text-xs font-medium text-emerald-700 bg-emerald-50 rounded-full px-2 py-0.5">Active</span>
                      : <span className="text-xs font-medium text-slate-500 bg-slate-100 rounded-full px-2 py-0.5">Deactivated</span>}
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    <button onClick={() => nav(`/super/tenants/${t.id}`)}
                      className="text-xs text-brand-600 hover:underline font-medium">Open →</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {show && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" onClick={close}>
          <form onSubmit={create} onClick={(e) => e.stopPropagation()}
            className="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
            <h2 className="text-lg font-bold text-slate-900 mb-1">New tenant</h2>
            <div className="flex items-center gap-1.5 mb-4">
              {steps.map((s, i) => (
                <div key={s} className="flex items-center gap-1.5">
                  <span className={`text-[11px] font-semibold rounded-full px-2 py-0.5 ${
                    step === i + 1 ? 'bg-brand-600 text-white' : step > i + 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400'}`}>
                    {i + 1}. {s}
                  </span>
                  {i < steps.length - 1 && <span className="text-slate-300 text-xs">→</span>}
                </div>
              ))}
            </div>

            {step === 1 && (
              <div className="grid grid-cols-2 gap-3">
                <div><label className="text-xs font-medium text-slate-600">Tenant name *</label>
                  <input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="acme" className={input} /><p className="text-[11px] text-slate-400 mt-1">Letters and numbers only — also the gateway mail folder.</p></div>
                <div><label className="text-xs font-medium text-slate-600">Company name</label>
                  <input value={form.company_name} onChange={(e) => set('company_name', e.target.value)} className={input} /></div>
                <div><label className="text-xs font-medium text-slate-600">Dynalink domain *</label>
                  <input value={form.domain} onChange={(e) => set('domain', e.target.value)} placeholder="1234.ExampleCo" className={input} /></div>
                <div><label className="text-xs font-medium text-slate-600">Dynalink username *</label>
                  <input value={form.dynalink_user} onChange={(e) => set('dynalink_user', e.target.value)} placeholder="6001" className={input} /></div>
                <div className="col-span-2"><label className="text-xs font-medium text-slate-600">Dynalink password *</label>
                  <input type="password" value={form.dynalink_pass} onChange={(e) => set('dynalink_pass', e.target.value)} className={input} /></div>
              </div>
            )}

            {step === 2 && (
              <div>
                <p className="text-sm text-slate-600">
                  SMS numbers on domain <strong>{form.domain.trim()}</strong>:
                </p>
                <p className="text-[11px] text-slate-400">
                  Every number in the domain, not just those on{' '}
                  {form.dynalink_user.trim()}. The extension shown is the user that owns each number.
                </p>
                {numbers.length > 8 && (
                  <input
                    value={numQ} onChange={(e) => setNumQ(e.target.value)}
                    placeholder="🔍 Search number or extension…" aria-label="Search SMS numbers"
                    className={input}
                  />
                )}
                <div className="border rounded-xl p-2 mt-2 space-y-1 max-h-64 overflow-y-auto">
                  {shownNumbers.map((n) => (
                    <label key={n.digits} className="flex items-center gap-2 text-sm text-slate-700 px-2 py-1 rounded-lg hover:bg-slate-50 cursor-pointer">
                      <input type="radio" name="main-number" checked={form.main === n.digits} onChange={() => set('main', n.digits)} className="shrink-0" />
                      <span className="min-w-0 truncate">{fmtPhone(n.number)}</span>
                      {n.dest && (
                        <span className="text-[11px] font-medium text-slate-500 bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5 shrink-0">
                          ext {n.dest}
                        </span>
                      )}
                    </label>
                  ))}
                  {shownNumbers.length === 0 && (
                    <p className="px-2 py-2 text-xs text-slate-400">No numbers match “{numQ.trim()}”.</p>
                  )}
                </div>
                <p className="text-[11px] text-slate-400 mt-2">
                  {numQ.trim()
                    ? `${shownNumbers.length} of ${numbers.length} shown. `
                    : `${numbers.length} number${numbers.length === 1 ? '' : 's'} found. `}
                  The main number is locked after creation and required on every agent of this tenant.
                </p>
              </div>
            )}

            {step === 3 && (
              <div>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" checked={form.withAdmin} onChange={(e) => set('withAdmin', e.target.checked)} />
                  Create first admin now
                </label>
                {form.withAdmin && (
                  <>
                  <p className="text-xs text-slate-500 mt-1">
                    Verified Dynalink identity{' '}
                    <strong>{form.dynalink_user.trim()}@{form.domain.trim()}</strong>{' '}
                    becomes this tenant&apos;s admin. The password below is the app login;
                    the Dynalink password stays stored separately for API calls.
                  </p>
                  <div className="grid grid-cols-2 gap-3 mt-2 border rounded-xl p-3 bg-slate-50">
                    <div><label className="text-xs font-medium text-slate-600">Admin username *</label>
                      <input value={form.a_username} onChange={(e) => set('a_username', e.target.value)} placeholder="sam" className={input} /></div>
                    <div><label className="text-xs font-medium text-slate-600">Password (min 8) *</label>
                      <input type="password" value={form.a_password} onChange={(e) => set('a_password', e.target.value)} className={input} /></div>
                    <div><label className="text-xs font-medium text-slate-600">First name *</label>
                      <input value={form.a_first} onChange={(e) => set('a_first', e.target.value)} className={input} /></div>
                    <div><label className="text-xs font-medium text-slate-600">Last name *</label>
                      <input value={form.a_last} onChange={(e) => set('a_last', e.target.value)} className={input} /></div>
                    <div className="col-span-2"><label className="text-xs font-medium text-slate-600">Secret question *</label>
                      <input value={form.a_q} onChange={(e) => set('a_q', e.target.value)} className={input} /></div>
                    <div className="col-span-2"><label className="text-xs font-medium text-slate-600">Secret answer *</label>
                      <input value={form.a_a} onChange={(e) => set('a_a', e.target.value)} className={input} /></div>
                  </div>
                  </>
                )}
              </div>
            )}

            <div className="flex gap-2 justify-end mt-5">
              {step > 1 && (
                <button type="button" onClick={() => setStep(step - 1)}
                  className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">← Back</button>
              )}
              {step === 1 && (
                <>
                  <button type="button" onClick={close}
                    className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
                  <button type="button" onClick={fresh ? () => setStep(2) : loadNumbers} disabled={loadingNums}
                    className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
                    {loadingNums ? 'Loading…' : fresh ? 'Continue →' : 'Load SMS numbers'}
                  </button>
                </>
              )}
              {step === 2 && (
                <button type="button" onClick={() => (form.main ? setStep(3) : toastError('Pick the main SMS number.'))}
                  className="text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-4 py-2 font-semibold">
                  Confirm & continue →
                </button>
              )}
              {step === 3 && (
                <button disabled={saving}
                  className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
                  {saving ? 'Creating…' : 'Create tenant'}
                </button>
              )}
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
