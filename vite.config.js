import {
    defineConfig,
    loadEnv,
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

const bladeRefreshPaths = [
    'resources/core/views/**/*.blade.php',
    'app/Modules/*/*/Views/**/*.blade.php',
    'extensions/*/*/Views/**/*.blade.php',
];

export default defineConfig(({ mode }) => {
    const environment = loadEnv(mode, process.cwd(), '');
    const hotReloadEnabled = ['1', 'true', 'yes', 'on'].includes(
        (environment.VITE_HOT_RELOAD ?? 'false').trim().toLowerCase(),
    );
    const frontendDomain = environment.FRONTEND_DOMAIN || 'local.blb.lara';

    return {
        plugins: [
            laravel({
                input: ['resources/app.css', 'resources/core/js/app.js'],
                // Hot reload is opt-in because it can interrupt work in an open
                // browser tab. Licensees choose it with VITE_HOT_RELOAD in .env.
                refresh: hotReloadEnabled ? bladeRefreshPaths : false,
            }),
            tailwindcss(),
        ],
        server: {
            host: '127.0.0.1',
            port: Number.parseInt(environment.VITE_PORT || '5173'),
            strictPort: true,
            origin: `https://${frontendDomain}`,
            hmr: hotReloadEnabled ? {
                host: frontendDomain,
                protocol: 'wss',
                clientPort: 443,
            } : false,
            cors: true,
            watch: {
                ignored: ['**/*.md'],
            },
        },
    };
});
