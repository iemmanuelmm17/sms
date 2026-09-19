import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_API_PROXY || 'http://192.168.18.2:8000';

  return {
    plugins: [react()],
    server: {
      host: '0.0.0.0',
      port: 5173,
      allowedHosts: ['.e2b.app', 'localhost', '127.0.0.1', '192.168.18.2', 'giver-delivery-unsecured.ngrok-free.dev'],
      proxy: {
        '/api': { target, changeOrigin: true, headers: {
            'ngrok-skip-browser-warning': 'true',
          }, },
        '/broadcasting': { target, changeOrigin: true, headers: {
            'ngrok-skip-browser-warning': 'true',
          }, },
      },
    },
  };
});