import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { api } from '../api/client';
import { useAuth } from './AuthContext';

const SockCtx = createContext({ lastEvent: null, lastSync: null, connected: false, simulateInbound: () => {} });
export const useSocket = () => useContext(SockCtx);

export function SocketProvider({ children }) {
  const { user, setUser, refreshUser } = useAuth();
  const [lastEvent, setLastEvent] = useState(null);
  const [lastSync, setLastSync] = useState(null);
  const [connected, setConnected] = useState(false);
  const echoRef = useRef(null);
  const subCountRef = useRef(0);      // how many times the channel has (re-)subscribed this connection
  const hiddenAtRef = useRef(0);      // when the tab was last backgrounded

  /**
   * Catch-up signal. Reverb does NOT replay events that fired while this
   * client was disconnected (ws drop, channel re-auth gap, laptop sleep,
   * flaky network), so without this a client that missed e.g. a convo-meta
   * assignment stays stale until the NEXT unrelated broadcast happens to
   * arrive. On any reconnect / long-hidden-tab return we emit a synthetic
   * `resync` lastSync; ReferenceDataContext answers it with a full refresh
   * and Messages/pages refetch their own lists.
   */
  const resync = useCallback((reason) => {
    setLastSync({ resource: 'resync', event: reason, _at: Date.now(), _id: Math.random().toString(36).substring(7) });
  }, []);

  useEffect(() => {
    if (!lastSync?.resource) return;
    if (!user) return;
    const r = lastSync.resource;
    if (r === 'agents' || r === 'company-settings' || r === 'email-sms-senders' || r === 'resync') {
      if (user.role === 'agent') {
        refreshUser?.().catch(() => {});
      }
    }
  }, [lastSync, user?.role]);

  // Reconnect identity: only tear the socket down when WHO is connected
  // changes (login/logout/role/domain). Depending on the whole `user` object
  // made every refreshUser() (fired on any `agents`/`company-settings`
  // broadcast for agents) rebuild the socket — and every rebuild opens a
  // multi-second window where incoming events are silently dropped.
  const connKey = user ? `${user.id ?? user.user ?? ''}|${user.role}|${user.domain}` : '';

  useEffect(() => {
    if (!user) return;
    let cancelled = false;
    subCountRef.current = 0;
    async function connectLive() {
      try {
        const [{ default: Echo }, { default: Pusher }] = await Promise.all([
          import('laravel-echo'), import('pusher-js'),
        ]);
        window.Pusher = Pusher;
        let rt = {};
        try { rt = (await api.realtime()) || {}; } catch {}
        const rtHost = rt.host || import.meta.env.VITE_REVERB_HOST;
        const rtPort = rt.port || import.meta.env.VITE_REVERB_PORT;
        const rtScheme = rt.scheme || import.meta.env.VITE_REVERB_SCHEME || 'http';
        const rtKey = rt.app_key || import.meta.env.VITE_REVERB_APP_KEY;

        const echo = new Echo({
          broadcaster: 'reverb',
          key: rtKey,
          wsHost: rtHost,
          wsPort: rtPort,
          wssPort: rtPort,
          forceTLS: rtScheme === 'https',
          enabledTransports: ['ws', 'wss'],
          authEndpoint: '/broadcasting/auth',
          authorizer: (channel, options) => {
            return {
              authorize: (socketId, callback) => {
                axios.post(options.authEndpoint, {
                  socket_id: socketId,
                  channel_name: channel.name
                }, { withCredentials: true })
                .then(response => callback(false, response.data))
                .catch(error => callback(true, error));
              }
            };
          }
        });

        echoRef.current = echo;
        const safe = (v) => String(v).replace(/[^A-Za-z0-9-]/g, '_');
        const chName = `sms.${safe(user.domain)}.shared`;
        const ch = echo.private(chName);
        
        ch.listen('.sms.incoming', (e) => {
          if (!cancelled) {
            setLastEvent({ ...e, _at: Date.now(), _id: Math.random().toString(36).substring(7) });
          }
        });
        ch.listen('.data.changed', (e) => {
          if (!cancelled) {
            setLastSync({ ...(e || {}), _at: Date.now(), _id: Math.random().toString(36).substring(7) });
          }
        });

        try {
          const scheme = rtScheme === 'https' ? 'wss' : 'ws';
          console.info(`[realtime] socket target: ${scheme}://${rtHost}:${rtPort} | channel: private-${chName}${rt.host ? ' (server settings)' : ' (.env)'}`);
          const conn = echo.connector?.pusher?.connection;
          conn?.bind('state_change', ({ current }) => {
            if (!cancelled) setConnected(current === 'connected');
          });
          const raw = echo.connector?.pusher?.channel(`private-${chName}`);
          raw?.bind('pusher:subscription_succeeded', () => {
            subCountRef.current += 1;
            if (subCountRef.current > 1) {
              console.info('[realtime] channel re-subscribed ✓ — catching up on missed events');
              if (!cancelled) resync('reconnected');
            } else {
              console.info('[realtime] channel subscribed ✓');
            }
          });
          raw?.bind('pusher:subscription_error', (err) => console.warn('[realtime] channel auth FAILED:', err));
        } catch {}
        
        setConnected(true);
      } catch (e) {
        console.warn('Reverb unavailable, realtime disabled:', e);
        setConnected(false);
      }
    }

    if (api.isDemo) {
      setConnected(true);
    } else {
      connectLive();
    }
    
    return () => { 
      cancelled = true; 
      try { echoRef.current?.disconnect(); } catch {} 
    };
  }, [connKey, resync]);

  // Second safety net: a long-backgrounded tab (laptop sleep, phone screen
  // off) or a network switch can drop events even when pusher believes it
  // stayed connected. Refetch once when the user comes back.
  useEffect(() => {
    if (api.isDemo) return;
    const onVis = () => {
      if (document.hidden) { hiddenAtRef.current = Date.now(); return; }
      const away = hiddenAtRef.current ? Date.now() - hiddenAtRef.current : 0;
      hiddenAtRef.current = 0;
      if (away > 60000 && user) resync('tab-focus');
    };
    const onOnline = () => { if (user) resync('network-back'); };
    document.addEventListener('visibilitychange', onVis);
    window.addEventListener('online', onOnline);
    return () => {
      document.removeEventListener('visibilitychange', onVis);
      window.removeEventListener('online', onOnline);
    };
  }, [user, resync]);

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
