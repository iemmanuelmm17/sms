import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    // LAN hosts come from VITE_ALLOWED_HOSTS (comma-separated). Localhost and
    // the sandbox preview host are always allowed.
    allowedHosts: ['.e2b.app', 'localhost', '127.0.0.1',
      ...(process.env.VITE_ALLOWED_HOSTS || '').split(',').map((s) => s.trim()).filter(Boolean)],
    // HMR client port override is only needed behind an https preview proxy.
    hmr: process.env.VITE_HMR_CLIENT_PORT ? { clientPort: Number(process.env.VITE_HMR_CLIENT_PORT) } : true,
    proxy: {
      // When a Laravel backend runs alongside, /api + /broadcasting proxy to it.
      '/api': { target: process.env.VITE_API_PROXY || 'http://localhost:8000', changeOrigin: true },
      '/broadcasting': { target: process.env.VITE_API_PROXY || 'http://localhost:8000', changeOrigin: true },
    },
  },
});
