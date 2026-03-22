import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import cssInjectedByJsPlugin from 'vite-plugin-css-injected-by-js'

export default defineConfig({
    plugins: [vue(), cssInjectedByJsPlugin()],
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
