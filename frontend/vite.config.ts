import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    cors: true,
    hmr: {
      clientPort: 443,
      protocol: 'wss',
      host: 'chargebacks.pfm-qa.com',
    },
    proxy: {
      '/api': 'http://backend:3000',
      '/oauth': 'http://backend:3000',
    },
  },
})
