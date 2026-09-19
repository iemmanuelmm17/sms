import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { api } from '../api/client';
import { useAuth } from './AuthContext';

/**
 * Realtime layer.
 *  - LIVE mode: Laravel Echo + Reverb on private-sms.{domain}.{user}.
 *    - `sms.incoming` → lastEvent (inbound SMS)
 *    - `data.changed` → lastSync (any mutation from any instance)
 *  - DEMO mode: simulated inbound message every so often + a manual "Simulate inbound" trigger.
 */
const SockCtx = createContext({ lastEvent: null, lastSync: null, connected: false, simulateInbound: () => {} });
export const useSocket = () => useContext(SockCtx);

export function SocketProvider({ children }) {
  const { user } = useAuth();
  const [lastEvent, setLastEvent] = useState(null);
  const [lastSync, setLastSync] = useState(null);
  const [connected, setConnected] = useState(false);
  const echoRef = useRef(null);

  useEffect(() => {
    if (!user) return;
    let cancelled = false;

    async function connectLive() {
      try {
        const [{ default: Echo }, { default: Pusher }] = await Promise.all([
          import('laravel-echo'), import('pusher-js'),
        ]);
        window.Pusher = Pusher;
        const echo = new Echo({
          broadcaster: 'reverb',
          key: import.meta.env.VITE_REVERB_APP_KEY,
          wsHost: import.meta.env.VITE_REVERB_HOST,
          wsPort: import.meta.env.VITE_REVERB_PORT,
          wssPort: import.meta.env.VITE_REVERB_PORT,
          forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
          enabledTransports: ['ws', 'wss'],
          authEndpoint: '/broadcasting/auth',
        });
        echoRef.current = echo;
        // Same sanitization as the backend (ChannelName): Dynalink domains
        // contain dots ("1180.DynaCloud") but Laravel channel params can't
        // match dots — unsanitized names fail auth with 403.
        const safe = (v) => String(v).replace(/[^A-Za-z0-9-]/g, '_');
        // Agents join their owner's channel (broadcasts are per Dynalink scope, not per agent).
        const scopeUser = user.role === 'agent' ? (user.scope_user || user.user) : user.user;
        const chName = `sms.${safe(user.domain)}.${safe(scopeUser)}`;
        const ch = echo.private(chName);
        ch.listen('.sms.incoming', (e) => {
          if (!cancelled) setLastEvent({ ...e, _at: Date.now() });
        });
        ch.listen('.data.changed', (e) => {
          if (!cancelled) setLastSync({ ...(e || {}), _at: Date.now() });
        });
        // Diagnostics: confirm the socket target + channel subscription in the
        // browser console (proves fresh code, correct host, and auth success).
        try {
          const scheme = (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https' ? 'wss' : 'ws';
          console.info(`[realtime] socket target: ${scheme}://${import.meta.env.VITE_REVERB_HOST}:${import.meta.env.VITE_REVERB_PORT} | channel: private-${chName}`);
          const raw = echo.connector?.pusher?.channel(`private-${chName}`);
          raw?.bind('pusher:subscription_succeeded', () => console.info('[realtime] channel subscribed ✓'));
          raw?.bind('pusher:subscription_error', (err) => console.warn('[realtime] channel auth FAILED — are you logged in? Check routes/channels.php allows this session:', err));
        } catch {}
        setConnected(true);
      } catch (e) {
        console.warn('Reverb unavailable, realtime disabled:', e);
        setConnected(false);
      }
    }

    if (api.isDemo) {
      setConnected(true); // demo "connected"
    } else {
      connectLive();
    }
    return () => { cancelled = true; try { echoRef.current?.disconnect(); } catch {} };
  }, [user]);

  // Demo helper: inject a fake inbound SMS so reviewers see the instant push.
  const simulateInbound = (sessionId, fromNumber, text) => {
    setLastEvent({
      id: `sim-${Date.now()}`, timestamp: new Date().toISOString().slice(0, 19).replace('T', ' '),
      type: 'sms', direction: 'orig', dialed: 12123527376,
      text: text || 'Demo inbound message — pushed instantly over WebSocket, no refresh needed.',
      'from-number': fromNumber || 19175778756,
      'messagesession-id': sessionId || null, _at: Date.now(), _sim: true,
    });
  };

  return <SockCtx.Provider value={{ lastEvent, lastSync, connected, simulateInbound }}>{children}</SockCtx.Provider>;
}
