import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        vue(),
    ],
    server: {
        host: 'localhost',
        port: 5187,
        strictPort: true,
        origin: 'http://localhost:5187',
        proxy: {
            '^/$': 'http://127.0.0.1:8000',
            '^/communication(?:/|$)': 'http://127.0.0.1:8000',
            '^/api(?:/|$)': 'http://127.0.0.1:8000',
            '^/sanctum(?:/|$)': 'http://127.0.0.1:8000',
        },
    },
});