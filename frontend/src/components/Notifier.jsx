import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';
import { api, contactName, fmtPhone } from '../api/client';
import { getPrefs, playSound, desktopNotify, unlockAudio } from '../lib/notify';

const digits = (v) => String(v ?? '').replace(/\D/g, '');
const samePhone = (a, b) => {
  a = digits(a); b = digits(b);
  if (!a || !b) return false;
  if (a === b) return true;
  return a.slice(-10) === b.slice(-10);
};

/**
 * Global incoming-message notifier. On every inbound realtime event:
 *  1) shows an in-app notification window (bottom-right, click to open chat),
 *  2) plays a sound if Settings → sound is on,
 *  3) fires a desktop (OS) notification if Settings → desktop is on.
 */
export default function Notifier() {
  const { lastEvent, lastSync } = useSocket();
  const { user } = useAuth();
  const navigate = useNavigate();
  const [contacts, setContacts] = useState([]);
  const [toasts, setToasts] = useState([]);

  useEffect(() => { api.contacts().then(setContacts).catch(() => {}); }, []);

  // Unlock audio on first click/keypress (browser autoplay policy).
  useEffect(() => {
    window.addEventListener('pointerdown', unlockAudio, { once: true });
    window.addEventListener('keydown', unlockAudio, { once: true });
    return () => {
      window.removeEventListener('pointerdown', unlockAudio);
      window.removeEventListener('keydown', unlockAudio);
    };
  }, []);

  const dismiss = (id) => setToasts((t) => t.filter((x) => x.id !== id));

  const openChat = (sid, from) => {
    navigate('/app/messages', { state: { openSession: sid || null, openFrom: from || null } });
  };

  useEffect(() => {
    if (!lastEvent || lastEvent.direction !== 'orig') return;
    const prefs = getPrefs();
    const from = lastEvent['from-number'];
    const c = contacts.find((x) =>
      ['phonenumber-cell', 'phonenumber-work', 'phonenumber-home', 'phonenumber-fax']
        .some((k) => samePhone(x[k], from))
    );
    const name = c ? contactName(c) : fmtPhone(from);
    const text = lastEvent.text || '[media message]';
    const sid = lastEvent['messagesession-id'] || null;

    const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    setToasts((t) => [...t.slice(-2), { id, name, text, sid, from }]);
    setTimeout(() => dismiss(id), 9000);

    if (prefs.sound) playSound();
    if (prefs.desktop) desktopNotify(`New SMS from ${name}`, text, () => openChat(sid, from));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastEvent]);

  // Keyword Alerts (admin-only): live toast the moment a watched keyword is
  // caught — inbound OR outbound. Click opens the alert feed.
  useEffect(() => {
    if (!lastSync || lastSync.resource !== 'keyword-alerts' || lastSync.action !== 'triggered') return;
    if (user?.role === 'agent') return;
    const p = lastSync.payload || {};
    const id = `ka-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const title = `🔔 "${p.keyword || 'Keyword'}" ${p.direction === 'out' ? 'sent' : 'received'}`;
    const body = `${p.rule_name ? `${p.rule_name} — ` : ''}${p.text || ''}`;
    setToasts((t) => [...t.slice(-2), { id, name: title, text: body, ka: true }]);
    setTimeout(() => dismiss(id), 9000);
    const prefs = getPrefs();
    if (prefs.sound) playSound();
    if (prefs.desktop) desktopNotify(title, body, () => navigate('/app/keyword-alerts'));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  if (toasts.length === 0) return null;

  return (
    <div className="fixed bottom-4 right-4 z-[100] space-y-2 w-80">
      {toasts.map((t) => (
        <div key={t.id}
          onClick={() => { if (t.ka) navigate('/app/keyword-alerts'); else openChat(t.sid, t.from); dismiss(t.id); }}
          className="bg-slate-900 text-white rounded-xl shadow-2xl p-3.5 cursor-pointer border border-slate-700 hover:bg-slate-800 transition">
          <div className="flex items-start justify-between gap-2">
            <div className="text-sm font-semibold">{t.ka ? t.name : `💬 ${t.name}`}</div>
            <button onClick={(e) => { e.stopPropagation(); dismiss(t.id); }}
              className="text-slate-400 hover:text-white font-bold leading-none">✕</button>
          </div>
          <div className="text-xs text-slate-300 mt-1 line-clamp-3 break-words">{t.text}</div>
          <div className="text-[11px] text-slate-400 mt-1.5">{t.ka ? 'Click to open Keyword Alerts →' : 'Click to open conversation →'}</div>
        </div>
      ))}
    </div>
  );
}
