import { useEffect, useMemo, useState } from 'react';
import { api, fmtPhone, fmtDateTime } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';

const digits = (v) => String(v ?? '').replace(/\D/g, '');

/** TCPA section: info | opt-in history | opt-out list. */
export default function TCPA() {
  const { lastSync } = useSocket();
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const [tab, setTab] = useState('optout'); // tcpa | optin | optout
  const [optIns, setOptIns] = useState([]);
  const [optOuts, setOptOuts] = useState([]);
  const [scopes, setScopes] = useState({}); // digits -> numbers array (enforcement scope)
  const [contacts, setContacts] = useState([]);
  const [phone, setPhone] = useState('');
  const [q, setQ] = useState('');

  const reload = () => {
    api.optEvents('opt_in').then((d) => setOptIns(Array.isArray(d) ? d : [])).catch(() => {});
    api.optOuts().then((d) => {
      const arr = Array.isArray(d) ? d : [];
      setOptOuts(arr); // the enforceable do-not-contact list — Remove works on these rows
      const m = {};
      arr.forEach((o) => { m[digits(o.phone)] = o.numbers || ['*']; });
      setScopes(m);
    }).catch(() => {});
  };
  useEffect(() => {
    reload();
    api.contacts().then((d) => setContacts(Array.isArray(d) ? d : [])).catch(() => {});
  }, []);
  useEffect(() => {
    if (lastSync?.resource === 'opt-events' || lastSync?.resource === 'optouts') reload();
  }, [lastSync]);

  const nameByPhone = useMemo(() => {
    const m = {};
    contacts.forEach((c) => {
      const nm = `${c['name-first-name'] || ''} ${c['name-last-name'] || ''}`.trim();
      ['phonenumber-cell', 'phonenumber-work', 'phonenumber-home', 'phone'].forEach((k) => {
        const d = digits(c[k]);
        if (d && nm) m[d] = nm;
      });
    });
    return m;
  }, [contacts]);
  const nameOf = (p) => {
    const d = digits(p);
    return nameByPhone[d]
      || (d.length === 11 && d.startsWith('1') ? nameByPhone[d.slice(1)] : '')
      || (d.length === 10 ? nameByPhone['1' + d] : '') || '';
  };
  const scopeOf = (p) => {
    const nums = scopes[digits(p)] || ['*'];
    if (nums.includes('*')) return 'all numbers';
    return nums.map((n) => fmtPhone(n)).join(', ');
  };

  const add = async () => {
    if (!phone.trim()) return;
    try {
      await api.addOptOut(phone.trim());
      setPhone('');
      reload();
      toastSuccess('Number added to do-not-contact');
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };
  const remove = async (p) => {
    if (!confirm(`Remove ${p} from do-not-contact? Sends to this number will be allowed again.`)) return;
    try { const r = await api.removeOptOut(p); reload(); toastSuccess(r && r.removed === false ? 'Already removed' : 'Number removed from do-not-contact'); } catch (e) { toastError(e.message); }
  };

  const fmtWhen = (iso) => (iso ? fmtDateTime(iso) : '—');

  const qd = q.replace(/\D/g, '');
  const list = (tab === 'optin' ? optIns : optOuts).filter((o) =>
    !qd || digits(o.phone_number || o.phone).includes(qd) || (nameOf(o.phone_number || o.phone).toLowerCase().includes(q.toLowerCase())));

  const tabs = [['tcpa', 'TCPA'], ['optin', `Opt-in (${optIns.length})`], ['optout', `Opt-out (${optOuts.length})`]];

  return (
    <div className="h-full overflow-y-auto bg-slate-50 p-4 md:p-6">
      <div className="max-w-2xl mx-auto">
        <h2 className="text-lg font-bold text-slate-800" title="Telephone Consumer Protection Act">🚫 TCPA</h2>
        <div className="flex gap-1 mt-2 mb-4 bg-slate-200/60 dark:bg-slate-800 rounded-lg p-1 w-fit">
          {tabs.map(([id, label]) => (
            <button key={id} onClick={() => setTab(id)}
              className={`text-sm font-medium rounded-md px-4 py-1.5 ${tab === id ? 'bg-white dark:bg-slate-600 shadow text-slate-900 dark:text-white' : 'text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-white'}`}>
              {label}
            </button>
          ))}
        </div>

        {tab === 'tcpa' && (
          <div className="bg-white rounded-xl border p-5 space-y-3 text-sm text-slate-600">
            <h3 className="text-base font-bold text-slate-800">A Definition of Telephone Consumer Protection Act</h3>
            <p>The Telephone Consumer Protection Act (TCPA) was enacted by Congress in 1991 to restrict telemarketing calls and the use of automatic telephone dialing systems and prerecorded voice messages. In 1992, the Federal Communications Commission (FCC) adopted implementing rules, including a requirement that companies maintain company-specific do-not-call lists.</p>
            <p>In 2012, the FCC revised its TCPA rules to require prior express written consent before autodialed or prerecorded telemarketing calls (robocalls), closing the &ldquo;established business relationship&rdquo; exemption and requiring an automated, interactive opt-out mechanism on every robocall.</p>
            <h3 className="text-base font-bold text-slate-800 pt-2">Challenges with TCPA</h3>
            <p>Technology and marketing practices evolve faster than TCPA regulation, creating gray areas around phone, text, and email contact. That uncertainty exposes businesses to compliance risk and significant per-violation fines.</p>
            <p>In 2015, the FCC changed course again &mdash; making it easier for consumers to revoke consent, limiting the volume of informational and non-telemarketing calls, and extending TCPA protections explicitly to text messages. A surge of scammer-driven complaints produced rules that also burden legitimate businesses.</p>
            <p>Mobile-first contact adds another compliance challenge: call centers need stronger number verification, consent is required before contacting mobile numbers regardless of promotional intent, and TCPA coverage extends to text messages, notifications, marketing messages, and mass communications.</p>
          </div>
        )}

        {tab !== 'tcpa' && (
          <>
            <p className="text-sm text-slate-500 mb-4">
              {tab === 'optin'
                ? 'Numbers that texted START or SUBSCRIBED. They can receive messages.'
                : 'Blocked from manual, auto-reply, and scheduled sends. STOP (or UNSUBSCRIBED) opts a number out of the business number it texted; START re-opts in everywhere.'}
            </p>
            <div className="bg-white rounded-xl border p-4 mb-4">
              {tab === 'optout' && (
                <div className="flex gap-2">
                  <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Add number…"
                    onKeyDown={(e) => { if (e.key === 'Enter') add(); }}
                    className="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                  <button onClick={add} className="text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4">Add</button>
                </div>
              )}
              <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔍 Search list…"
                className={`w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 bg-slate-50 ${tab === 'optout' ? 'mt-2' : ''}`} />
            </div>
            <div className="bg-white rounded-xl border divide-y">
              {list.map((o, i) => {
                const p = o.phone_number || o.phone;
                const nm = nameOf(p);
                return (
                  <div key={`${p}-${i}`} className="flex items-center gap-3 px-4 py-2.5">
                    <div className="min-w-0">
                      <div className="text-sm font-medium text-slate-800">{nm || fmtPhone(p)}</div>
                      {nm && <div className="text-[11px] text-slate-400">{fmtPhone(p)}</div>}
                    </div>
                    <span className="text-[11px] px-2 py-0.5 rounded-full font-medium bg-slate-100 text-slate-600 uppercase">
                      {(o.keyword || o.source || (tab === 'optin' ? 'start' : 'stop')).replace('stop-keyword', 'stop')}
                    </span>
                    {tab === 'optout' && <span className="text-[11px] text-slate-400">{scopeOf(p)}</span>}
                    <span className="text-[11px] text-slate-400">{fmtWhen(o.occurred_at || o.at)}</span>
                    {tab === 'optout' && !isAgent && (
                      <button onClick={() => remove(p)} className="ml-auto text-xs text-red-500 hover:underline shrink-0">Remove</button>
                    )}
                  </div>
                );
              })}
              {list.length === 0 && (
                <div className="p-6 text-sm text-slate-400 text-center">
                  {tab === 'optin' ? 'No opt-ins yet.' : 'No opted-out numbers.'}
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
