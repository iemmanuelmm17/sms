import { useEffect, useState } from 'react';
import { api } from '../../api/client';
import { toastError, toastSuccess } from '../../lib/toast';

const badge = (source) => {
  const map = {
    database: 'bg-sky-50 text-sky-700 border-sky-200',
    env: 'bg-slate-100 text-slate-600 border-slate-200',
    auto: 'bg-slate-100 text-slate-600 border-slate-200',
    none: 'bg-amber-50 text-amber-700 border-amber-200',
  };
  const label = { database: 'DB override', env: '.env', auto: 'auto', none: 'not set' }[source] || source;
  return <span className={`ml-2 text-[10px] font-medium border rounded-full px-2 py-0.5 ${map[source] || map.none}`}>{label}</span>;
};

export default function SuperMailGateway() {
  const [settings, setSettings] = useState(null);
  const [mail, setMail] = useState({ smtp_host: '', smtp_port: '', smtp_encryption: 'tls', smtp_username: '', from_address: '', from_name: '', imap_host: '', imap_port: '', imap_encryption: 'ssl', imap_username: '', inbound_domain: '', cap_sender_daily: '', cap_dest_hourly: '' });
  const [smtpPass, setSmtpPass] = useState('');
  const [imapPass, setImapPass] = useState('');
  const [testTo, setTestTo] = useState('');
  const [testBusy, setTestBusy] = useState(false);
  const [inboxBusy, setInboxBusy] = useState(false);
  const [inboxMsg, setInboxMsg] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const s = await api.superSettings();
      setSettings(s);
      const m = s?.mail || {};
      setMail({
        smtp_host: m.smtp_host?.value || '', smtp_port: m.smtp_port?.value || '', smtp_encryption: m.smtp_encryption?.value || 'tls',
        smtp_username: m.smtp_username?.value || '', from_address: m.from_address?.value || '', from_name: m.from_name?.value || '',
        imap_host: m.imap_host?.value || '', imap_port: m.imap_port?.value || '', imap_encryption: m.imap_encryption?.value || 'ssl',
        imap_username: m.imap_username?.value || '', inbound_domain: m.inbound_domain?.value || '',
        cap_sender_daily: m.cap_sender_daily?.value || '', cap_dest_hourly: m.cap_dest_hourly?.value || '',
      });
      setSmtpPass(''); setImapPass('');
    } catch (e) { toastError(e?.response?.data?.message || 'Failed to load settings.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const saveMail = async () => {
    setSaving(true);
    try {
      const payload = {
        mail_smtp_host: mail.smtp_host.trim(), mail_smtp_port: mail.smtp_port === '' ? null : Number(mail.smtp_port),
        mail_smtp_encryption: mail.smtp_encryption, mail_smtp_username: mail.smtp_username.trim(),
        mail_from_address: mail.from_address.trim(), mail_from_name: mail.from_name.trim(),
        mail_imap_host: mail.imap_host.trim(), mail_imap_port: mail.imap_port === '' ? null : Number(mail.imap_port),
        mail_imap_encryption: mail.imap_encryption, mail_imap_username: mail.imap_username.trim(),
        mail_inbound_domain: mail.inbound_domain.trim(),
        mail_cap_sender_daily: mail.cap_sender_daily === '' ? null : Number(mail.cap_sender_daily),
        mail_cap_dest_hourly: mail.cap_dest_hourly === '' ? null : Number(mail.cap_dest_hourly),
      };
      if (smtpPass) payload.mail_smtp_password = smtpPass;
      if (imapPass) payload.mail_imap_password = imapPass;
      const r = await api.superSettingsUpdate(payload);
      setSettings(r); setSmtpPass(''); setImapPass('');
      toastSuccess('Email gateway saved.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setSaving(false); }
  };

  const revertMailPass = async (key) => {
    if (!window.confirm('Clear the stored password and fall back to .env?')) return;
    setSaving(true);
    try {
      const r = await api.superSettingsUpdate({ [key]: '' });
      setSettings(r);
      toastSuccess('Password override cleared.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Failed.'); }
    finally { setSaving(false); }
  };

  const sendTestMail = async () => {
    if (!testTo.trim()) return toastError('Enter an address to send the test to.');
    setTestBusy(true);
    try {
      await api.mailTestSend(testTo.trim());
      toastSuccess(`Test email sent to ${testTo.trim()}.`);
    } catch (ex) { toastError(ex?.response?.data?.message || 'Test failed.'); }
    finally { setTestBusy(false); }
  };

  const checkInbox = async () => {
    setInboxBusy(true); setInboxMsg('');
    try {
      const r = await api.mailCheckInbox();
      const parts = Object.entries(r?.folders || {}).map(([name, n]) => `${name}: ${n}`);
      setInboxMsg(parts.length ? `Connected — ${parts.join(' · ')} unread.` : 'Connected.');
    } catch (ex) { setInboxMsg(ex?.response?.data?.message || 'Inbox check failed.'); }
    finally { setInboxBusy(false); }
  };

  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';

  if (loading) return <div className="text-sm text-slate-500">Loading…</div>;

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-4">Email gateway</h1>
      {(() => {
        const lp = settings?.mail?.last_poll;
        const at = lp?.at ? new Date(lp.at).getTime() : 0;
        const ageS = at ? Math.max(0, Math.round((Date.now() - at) / 1000)) : null;
        const stale = !lp || lp.ok === false || ageS === null || ageS > 600;
        const age = ageS === null ? 'never' : ageS < 90 ? `${ageS}s ago` : `${Math.round(ageS / 60)}m ago`;
        return (
          <div className={`rounded-xl border p-4 mb-4 text-sm ${stale ? 'bg-red-50 border-red-200 text-red-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800'}`}>
            <span className="font-semibold">{stale ? 'Poll overdue' : 'Polling'}:</span>{' '}
            {lp?.ok === false ? <>last poll failed {age} ({lp.error || 'error'})</>
              : !lp ? <>no poll recorded yet — cron <code className="bg-white/70 rounded px-1">php artisan schedule:run</code> may not be running</>
              : <>last poll {age}{lp.ready === false ? ' (inbox not configured)' : <> — fetched {lp.fetched}, sent {lp.sent}, replies {lp.replies}, skipped {lp.skipped}, purged {lp.purged}, errors {lp.errors}</>}</>}
            {stale && lp?.ok !== false && <div className="text-xs mt-1 opacity-80">The poll runs every minute — if this stays stale, check cron.</div>}
          </div>
        );
      })()}
      <div className="bg-white border rounded-xl p-5 max-w-xl">
        <p className="text-xs text-slate-400 mb-4">Shared by every tenant. Precedence: database override → .env → built-in default. Clear a field to fall back to .env.</p>
        <h3 className="text-xs font-bold text-slate-700 uppercase tracking-wide">Sending (SMTP)</h3>
        <div className="grid grid-cols-2 gap-3 mt-2">
          <div className="col-span-2">
            <label className="text-xs font-medium text-slate-600">SMTP host {badge(settings?.mail?.smtp_host?.source)}</label>
            <input value={mail.smtp_host} onChange={(e) => setMail({ ...mail, smtp_host: e.target.value })} placeholder="smtp.example.com" className={input} autoComplete="off" />
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Port {badge(settings?.mail?.smtp_port?.source)}</label>
            <input value={mail.smtp_port} onChange={(e) => setMail({ ...mail, smtp_port: e.target.value })} placeholder="587" inputMode="numeric" className={input} autoComplete="off" />
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Encryption {badge(settings?.mail?.smtp_encryption?.source)}</label>
            <select value={mail.smtp_encryption} onChange={(e) => setMail({ ...mail, smtp_encryption: e.target.value })} className={input}>
              <option value="tls">STARTTLS</option>
              <option value="ssl">SSL (implicit)</option>
              <option value="none">None</option>
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Username {badge(settings?.mail?.smtp_username?.source)}</label>
            <input value={mail.smtp_username} onChange={(e) => setMail({ ...mail, smtp_username: e.target.value })} className={input} autoComplete="off" />
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Password {badge(settings?.mail?.smtp_password?.source)}</label>
            <input type="password" value={smtpPass} onChange={(e) => setSmtpPass(e.target.value)}
              placeholder={settings?.mail?.smtp_password?.set ? '•••••••• (stored — blank keeps it)' : 'not set'} className={input} autoComplete="new-password" />
            {settings?.mail?.smtp_password?.source === 'database' && (
              <button type="button" onClick={() => revertMailPass('mail_smtp_password')}
                className="mt-1 text-[11px] text-amber-600 hover:underline">Clear DB override (fall back to .env)</button>
            )}
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">From address {badge(settings?.mail?.from_address?.source)}</label>
            <input value={mail.from_address} onChange={(e) => setMail({ ...mail, from_address: e.target.value })} placeholder="sms@example.com" className={input} autoComplete="off" />
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">From name {badge(settings?.mail?.from_name?.source)}</label>
            <input value={mail.from_name} onChange={(e) => setMail({ ...mail, from_name: e.target.value })} placeholder="Acme SMS" className={input} autoComplete="off" />
          </div>
        </div>
        <h3 className="text-xs font-bold text-slate-700 uppercase tracking-wide mt-5">Receiving (IMAP)</h3>
        <div className="grid grid-cols-2 gap-3 mt-2">
          <div className="col-span-2">
            <label className="text-xs font-medium text-slate-600">IMAP host {badge(settings?.mail?.imap_host?.source)}</label>
            <input value={mail.imap_host} onChange={(e) => setMail({ ...mail, imap_host: e.target.value })} placeholder="imap.example.com" className={input} autoComplete="off" />
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Port {badge(settings?.mail?.imap_port?.source)}</label>
            <input value={mail.imap_port} onChange={(e) => setMail({ ...mail, imap_port: e.target.value })} placeholder="993" inputMode="numeric" className={input} autoComplete="off" />
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Encryption {badge(settings?.mail?.imap_encryption?.source)}</label>
            <select value={mail.imap_encryption} onChange={(e) => setMail({ ...mail, imap_encryption: e.target.value })} className={input}>
              <option value="ssl">SSL (implicit)</option>
              <option value="tls">STARTTLS</option>
              <option value="none">None</option>
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Username {badge(settings?.mail?.imap_username?.source)}</label>
            <input value={mail.imap_username} onChange={(e) => setMail({ ...mail, imap_username: e.target.value })} className={input} autoComplete="off" />
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Password {badge(settings?.mail?.imap_password?.source)}</label>
            <input type="password" value={imapPass} onChange={(e) => setImapPass(e.target.value)}
              placeholder={settings?.mail?.imap_password?.set ? '•••••••• (stored — blank keeps it)' : 'not set'} className={input} autoComplete="new-password" />
            {settings?.mail?.imap_password?.source === 'database' && (
              <button type="button" onClick={() => revertMailPass('mail_imap_password')}
                className="mt-1 text-[11px] text-amber-600 hover:underline">Clear DB override (fall back to .env)</button>
            )}
          </div>
          <div className="col-span-2">
            <label className="text-xs font-medium text-slate-600">Inbound domain {badge(settings?.mail?.inbound_domain?.source)}</label>
            <input value={mail.inbound_domain} onChange={(e) => setMail({ ...mail, inbound_domain: e.target.value })} placeholder="sms.example.com" className={input} autoComplete="off" />
            <p className="text-[11px] text-slate-400 mt-1">Bare domain only. Its mailbox must catch every address at the domain (each SMS number + reply codes share one mailbox).</p>
          </div>
        </div>
        <h3 className="text-xs font-bold text-slate-700 uppercase tracking-wide mt-5">Sending limits</h3>
        <div className="grid grid-cols-2 gap-3 mt-2">
          <div>
            <label className="text-xs font-medium text-slate-600">Per sender / day {badge(settings?.mail?.cap_sender_daily?.source)}</label>
            <input value={mail.cap_sender_daily} onChange={(e) => setMail({ ...mail, cap_sender_daily: e.target.value })} placeholder="100" inputMode="numeric" className={input} autoComplete="off" />
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Per number / hour {badge(settings?.mail?.cap_dest_hourly?.source)}</label>
            <input value={mail.cap_dest_hourly} onChange={(e) => setMail({ ...mail, cap_dest_hourly: e.target.value })} placeholder="10" inputMode="numeric" className={input} autoComplete="off" />
          </div>
        </div>
        <p className="text-[11px] text-slate-400 mt-1">0 = unlimited; failures never burn quota. Inbound mail is polled every minute.</p>
        <div className="flex justify-end mt-5">
          <button type="button" onClick={saveMail} disabled={saving}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
            {saving ? 'Saving…' : 'Save email gateway'}
          </button>
        </div>
        <div className="border-t mt-4 pt-4">
          <h3 className="text-xs font-bold text-slate-700 uppercase tracking-wide">Test gateway</h3>
          <div className="flex gap-2 mt-2">
            <input value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="you@example.com"
              className="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" autoComplete="off" />
            <button type="button" onClick={sendTestMail} disabled={testBusy || saving}
              className="text-sm bg-slate-900 hover:bg-slate-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
              {testBusy ? 'Sending…' : 'Send test'}
            </button>
          </div>
          <div className="flex items-center gap-2 mt-2">
            <button type="button" onClick={checkInbox} disabled={inboxBusy || saving}
              className="text-sm border border-slate-300 hover:bg-slate-50 disabled:opacity-50 rounded-lg px-4 py-2 font-semibold">
              {inboxBusy ? 'Checking…' : 'Check inbox'}
            </button>
            {inboxMsg && <span className="text-xs text-slate-500">{inboxMsg}</span>}
          </div>
          <p className="text-[11px] text-slate-400 mt-2">The test sends from the From address above. Inbox check logs into IMAP and counts unread.</p>
        </div>
      </div>
    </div>
  );
}
