import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
  base: '/app/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  server: {
    proxy: {
      // Default matches typical Linux Apache (port 80). Homebrew httpd often uses 8080 — set in shell:
      //   VITE_API_PROXY_TARGET=http://127.0.0.1:8080 npm run dev
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET ?? 'http://127.0.0.1',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: path.resolve(__dirname, '../public/app'),
    emptyOutDir: true,
  },
});
