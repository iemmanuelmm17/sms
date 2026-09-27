import { createContext, useContext, useEffect, useRef, useState } from 'react';
import axios from 'axios'; // Import standard axios directly for the auth request
import { api } from '../api/client';
import { useAuth } from './AuthContext';

/**
 * Realtime layer.
 *  - LIVE mode: Laravel Echo + Reverb on the SHARED domain room
 *    private-sms.{domain}.shared (one per domain, all roles — agents on
 *    different user extensions included).
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

        // Fallback to localhost:8000 if VITE_API_BASE_URL is not set in your .env
        const backendUrl = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000';

        // Realtime connection target: Super → Settings (saved server-side,
        // served by /api/realtime) wins; the build-time .env values are the
        // per-field fallback. Fails soft — offline/demo keeps the .env values.
        let rt = {};
        try { rt = (await api.realtime()) || {}; } catch { /* stay on .env */ }
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
          
          // Absolute path to the Laravel API server to prevent Vite port routing leaks
          authEndpoint: `${backendUrl}/broadcasting/auth`,
          
          // Intercept authorization using standard axios to force session inclusion
          authorizer: (channel, options) => {
            return {
              authorize: (socketId, callback) => {
                axios.post(options.authEndpoint, {
                  socket_id: socketId,
                  channel_name: channel.name
                }, {
                  withCredentials: true // Crucial to allow BroadcastScope::fromSession() to validate
                })
                .then(response => callback(false, response.data))
                .catch(error => callback(true, error));
              }
            };
          }
        });

        echoRef.current = echo;
        
        // Same sanitization as the backend (ChannelName): Dynalink domains
        // contain dots ("1180.DynaCloud") but Laravel channel params can't
        // match dots — unsanitized names fail auth with 403.
        const safe = (v) => String(v).replace(/[^A-Za-z0-9-]/g, '_');
        
        // ONE shared room per Dynalink domain: private-sms.{domain}.shared.
        // The user/extension is deliberately NOT in the channel name — the
        // domain's admin and every agent (each on a different user
        // extension) all join the same room, and the backend sends every
        // broadcast (mutations AND inbound SMS) to that same room. The
        // payload's scope_user carries the token (kept for future room
        // scheme changes); 'shared' is the historical agent-room token.
        const scopeUser = user.scope_user || 'shared';
		const chName = `sms.${safe(user.domain)}.${safe(scopeUser)}`;
		const ch = echo.private(chName);
        
        ch.listen('.sms.incoming', (e) => {
		  // 1. Force a clean, brand-new object reference with a distinct timestamp
		  if (!cancelled) {
			setLastEvent({
			  ...e, 
			  _at: Date.now(), 
			  _id: Math.random().toString(36).substring(7) // Ensures distinct state mutation recognition
			});
		  }
		});

		ch.listen('.data.changed', (e) => {
		  if (!cancelled) {
			setLastSync({
			  ...(e || {}), 
			  _at: Date.now(),
			  _id: Math.random().toString(36).substring(7) // Forces a distinct structural signature
			});
		  }
		});

        
        // Diagnostics: confirm the socket target + channel subscription in the
        // browser console (proves fresh code, correct host, and auth success).
        try {
          const scheme = rtScheme === 'https' ? 'wss' : 'ws';
          console.info(`[realtime] socket target: ${scheme}://${rtHost}:${rtPort} | channel: private-${chName}${rt.host ? ' (server settings)' : ' (.env)'}`);
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
    
    return () => { 
      cancelled = true; 
      try { 
        echoRef.current?.disconnect(); 
      } catch {} 
    };
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
