import { useEffect, useState } from 'react';
import ContactForm, { EMPTY_CONTACT } from './ContactForm';
import { api } from '../api/client';
import { toastSuccess } from '../lib/toast';

/**
 * Global "add as contact" modal, mounted once in App.
 * Fire from anywhere: window.dispatchEvent(new CustomEvent('quick-add-contact', { detail: { phone } }))
 * On save it fires 'contacts-changed' so lists refresh.
 */
export default function QuickAddContact() {
  const [phone, setPhone] = useState(null);
  const [companies, setCompanies] = useState([]);

  useEffect(() => {
    const h = (e) => {
      setPhone(e.detail?.phone || '');
      // Load the roster so the picker can suggest existing companies and we
      // can tell a genuinely new one from a typo of an existing one.
      api.companies().then((c) => setCompanies(Array.isArray(c) ? c : [])).catch(() => {});
    };
    window.addEventListener('quick-add-contact', h);
    return () => window.removeEventListener('quick-add-contact', h);
  }, []);

  if (phone === null) return null;

  return (
    <ContactForm
      initial={{ ...EMPTY_CONTACT, 'phonenumber-cell': phone }}
      companies={companies}
      onClose={() => setPhone(null)}
      onSaved={(saved) => {
        setPhone(null);
        window.dispatchEvent(new CustomEvent('contacts-changed'));
        // ContactForm leaves company creation to its caller. This path never
        // did it, so typing a new company here silently dropped it.
        const company = String((saved || {}).company || '').trim();
        if (company && !companies.some((c) => (c.name || '').toLowerCase() === company.toLowerCase())) {
          api.createCompany({ name: company })
            .then(() => {
              window.dispatchEvent(new CustomEvent('companies-changed'));
              toastSuccess(`Contact saved — company “${company}” created`);
            })
            .catch(() => toastSuccess('Contact saved'));
          return;
        }
        toastSuccess('Contact saved');
      }}
    />
  );
}

export const quickAddContact = (phone) =>
  window.dispatchEvent(new CustomEvent('quick-add-contact', { detail: { phone: String(phone ?? '') } }));
