import { useState } from 'react';
import { api, contactId } from '../api/client';
import { toastError } from '../lib/toast';
import Modal from './Modal';

export const EMPTY_CONTACT = {
  'name-first-name': '', 'name-middle-name': '', 'name-last-name': '',
  email: '', company: '',
  'phonenumber-work': '', 'phonenumber-cell': '', 'phonenumber-home': '', 'phonenumber-fax': '',
  shared: false,
};

const digits = (v) => String(v ?? '').replace(/\D/g, '');
const PHONE_FIELDS = [
  ['phonenumber-cell', 'Cellphone'], ['phonenumber-work', 'Work'],
  ['phonenumber-home', 'Home'], ['phonenumber-fax', 'Fax'],
];

/** Shared add/edit contact form (persistent modal, Esc to close).
 * `companies` feeds the searchable company dropdown; a company with no
 * match gets one auto-created on save (handled by the caller). */
export default function ContactForm({ initial, companies = [], onClose, onSaved }) {
  const [f, setF] = useState(initial);
  const [busy, setBusy] = useState(false);
  const isNew = !contactId(initial);
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));

  const save = async () => {
    if (!String(f['name-first-name'] || '').trim()) return toastError('First name is required.');
    if (!String(f['name-last-name'] || '').trim()) return toastError('Last name is required.');
    if (!String(f['phonenumber-cell'] || '').trim()) return toastError('Cellphone number is required.');
    // Reject invalid phone numbers: any filled phone must have 10+ digits.
    for (const [k, label] of PHONE_FIELDS) {
      const v = String(f[k] || '').trim();
      if (v && digits(v).length < 10) {
        return toastError(`${label} number is invalid — needs at least 10 digits (extensions like "${v}" can't receive SMS).`);
      }
    }
    setBusy(true);
    try {
      if (isNew) await api.createContact(f);
      else await api.updateContact(contactId(initial), f);
      onSaved(f);
    } catch (e) { toastError('Save failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  const input = (k, label, ph = '') => (
    <div><label className="text-xs font-medium text-slate-600">{label}</label>
      <input value={f[k] || ''} onChange={(e) => set(k, e.target.value)} placeholder={ph}
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" /></div>
  );

  return (
    <Modal onClose={onClose}>
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-lg font-bold">{isNew ? 'Add Contact' : 'Update Contact'}</h2>
        <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
      </div>
      <div className="space-y-2.5">
        <div className="grid grid-cols-2 gap-2">{input('name-first-name', 'First name *')}{input('name-last-name', 'Last name *')}</div>
        {input('name-middle-name', 'Middle name')}
        <div className="grid grid-cols-2 gap-2">
          {input('email', 'Email')}
          <div><label className="text-xs font-medium text-slate-600">Company</label>
            <input value={f.company || ''} onChange={(e) => set('company', e.target.value)}
              placeholder="Type or pick a company…" list="contact-company-list"
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
            <datalist id="contact-company-list">
              {[...new Set(companies.map((g) => g.name).filter(Boolean))].map((c) => <option key={c} value={c} />)}
            </datalist>
          </div>
        </div>
        {String(f.company || '').trim() && !companies.some((g) => (g.name || '').toLowerCase() === String(f.company).trim().toLowerCase()) && (
          <p className="text-[11px] text-brand-700">✨ New company — it will be created on save.</p>
        )}
        <div className="grid grid-cols-2 gap-2">
          {input('phonenumber-cell', 'Cellphone *')}{input('phonenumber-work', 'Work')}
          {input('phonenumber-home', 'Home')}{input('phonenumber-fax', 'Fax')}
        </div>
        <p className="text-[11px] text-slate-400">Phone numbers need 10+ digits. Short work extensions can't receive SMS.</p>
        {isNew ? (
          <label className="flex items-start gap-2 text-xs text-slate-600 bg-slate-50 border rounded-lg px-3 py-2.5 cursor-pointer select-none">
            <input type="checkbox" checked={!!f.shared} onChange={(e) => set('shared', e.target.checked)}
              className="mt-0.5 accent-brand-600" />
            <span>
              <strong className="text-slate-800">Shared contact</strong>
              <span className="block text-[11px] text-slate-500 mt-0.5">
                Saves to the domain&apos;s shared address book — visible to every user (admin and agents).
                Unchecked saves to your personal book only.
              </span>
            </span>
          </label>
        ) : initial.shared ? (
          <p className="text-[11px] text-brand-700 bg-brand-50 border border-brand-200 rounded-lg px-3 py-2">
            <strong>Shared contact</strong> — lives in the domain&apos;s shared address book; every user sees it.
          </p>
        ) : null}
      </div>
      <button onClick={save} disabled={busy} className="mt-4 w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
        {busy ? 'Saving…' : isNew ? 'Add Contact' : 'Save Changes'}
      </button>
    </Modal>
  );
}
