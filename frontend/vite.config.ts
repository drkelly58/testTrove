import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import { resolveBuildMeta } from './buildMeta';

/**
 * Proxy `/api/*` → `http://127.0.0.1` (port 80) by default, matching earlier repo behavior / typical Apache setups.
 * For `php -S …:8080`, set VITE_API_PROXY_TARGET or `frontend/.env.development`.
 */
const DEFAULT_API_PROXY_TARGET = 'http://127.0.0.1';

export default defineConfig(({ mode }) => {
  const buildMeta = resolveBuildMeta();
  if (!process.env.VITE_APP_BUILD_ID?.trim()) {
    process.env.VITE_APP_BUILD_ID = buildMeta.buildId;
  }
  if (!process.env.VITE_APP_BUILD_TIME?.trim()) {
    process.env.VITE_APP_BUILD_TIME = buildMeta.buildTime;
  }
  if (!process.env.VITE_APP_BUILD_BRANCH?.trim()) {
    process.env.VITE_APP_BUILD_BRANCH = buildMeta.buildBranch;
  }

  const envDir = path.resolve(__dirname);
  const env = loadEnv(mode, envDir, '');
  const shellTarget =
    typeof process.env.VITE_API_PROXY_TARGET === 'string' && process.env.VITE_API_PROXY_TARGET.trim()
      ? process.env.VITE_API_PROXY_TARGET.trim()
      : '';
  const fileTarget =
    typeof env.VITE_API_PROXY_TARGET === 'string' && env.VITE_API_PROXY_TARGET.trim()
      ? env.VITE_API_PROXY_TARGET.trim()
      : '';

  const apiProxyTarget =
    shellTarget || fileTarget || DEFAULT_API_PROXY_TARGET;

  return {
    base: '/app/',
    plugins: [vue()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, 'src'),
      },
    },
    server: {
      proxy: {
        '/api': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
      },
    },
    build: {
      outDir: path.resolve(__dirname, '../public/app'),
      emptyOutDir: true,
    },
  };
});
