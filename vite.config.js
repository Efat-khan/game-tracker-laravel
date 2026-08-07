import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
    build: {
        rollupOptions: {
            output: {
                // Recharts is the only heavy dependency; splitting it keeps the
                // login and public check-in screens small. Rolldown (Vite 8)
                // wants the function form, not a map.
                manualChunks: (id) => (id.includes('node_modules/recharts') ? 'charts' : undefined),
            },
        },
    },
});
