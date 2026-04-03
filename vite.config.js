import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Read FRONTEND_DOMAIN from env or .env file.
// Each instance (main, worktree) has its own domain; don't derive from APP_ENV.
let frontendDomain = process.env.FRONTEND_DOMAIN || '';
if (!frontendDomain) {
    try {
        const envFile = readFileSync(resolve(__dirname, '.env'), 'utf8');
        const match = envFile.match(/^FRONTEND_DOMAIN=(.+)$/m);
        if (match) {
            frontendDomain = stripWrappingQuotes(match[1].trim());
        }
    } catch (e) {
        if (e.code !== 'ENOENT') {
            throw e;
        }
    }
}
frontendDomain = frontendDomain || 'local.blb.lara';

function stripWrappingQuotes(value) {
    const isDoubleQuoted = value.startsWith('"') && value.endsWith('"');
    const isSingleQuoted = value.startsWith("'") && value.endsWith("'");

    return isDoubleQuoted || isSingleQuoted ? value.slice(1, -1) : value;
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/app.css', 'resources/core/js/app.js'],
            refresh: [
                'resources/core/views/**/*.blade.php',
                'resources/core/css/**/*.css',
                'resources/core/js/**/*.{js,ts,vue}',
                ...(process.env.VITE_THEME_DIR ? [
                    `resources/extensions/${process.env.VITE_THEME_DIR}/views/**/*.blade.php`,
                    `resources/extensions/${process.env.VITE_THEME_DIR}/css/**/*.css`,
                    `resources/extensions/${process.env.VITE_THEME_DIR}/js/**/*.{js,ts,vue}`,
                ] : []),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        host: '127.0.0.1',
        port: Number.parseInt(process.env.VITE_PORT || '5173'),
        strictPort: true,
        origin: `https://${frontendDomain}`,
        hmr: {
            host: frontendDomain,
            protocol: 'wss',
            clientPort: 443,
        },
        cors: true,
    },
});
