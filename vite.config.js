import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [vue()],
    build: {
        lib: {
            entry: 'src/index.js',
            formats: ['iife'],
            name: '__EluthPluginEntry',
            fileName: () => 'index.js',
        },
        rollupOptions: {
            external: ['vue'],
            output: {
                globals: { vue: 'Vue' },
            },
        },
        outDir: 'dist',
    },
})
