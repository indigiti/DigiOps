import { defineConfig } from 'vite'

export default defineConfig({
  base: '/digiops/',
  publicDir: 'public',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: { input: 'index.html' }
  }
})
