import { useEffect, useState } from 'react';
import ContactForm, { EMPTY_CONTACT } from './ContactForm';
import { toastSuccess } from '../lib/toast';

/**
 * Global "add as contact" modal, mounted once in App.
 * Fire from anywhere: window.dispatchEvent(new CustomEvent('quick-add-contact', { detail: { phone } }))
 * On save it fires 'contacts-changed' so lists refresh.
 */
export default function QuickAddContact() {
  const [phone, setPhone] = useState(null);

  useEffect(() => {
    const h = (e) => setPhone(e.detail?.phone || '');
    window.addEventListener('quick-add-contact', h);
    return () => window.removeEventListener('quick-add-contact', h);
  }, []);

  if (phone === null) return null;

  return (
    <ContactForm
      initial={{ ...EMPTY_CONTACT, 'phonenumber-cell': phone }}
      onClose={() => setPhone(null)}
      onSaved={() => {
        setPhone(null);
        toastSuccess('Contact saved');
        window.dispatchEvent(new CustomEvent('contacts-changed'));
      }}
    />
  );
}

export const quickAddContact = (phone) =>
  window.dispatchEvent(new CustomEvent('quick-add-contact', { detail: { phone: String(phone ?? '') } }));
