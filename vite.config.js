import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Vite is launched outside Laravel and does not auto-load .env, so we parse the
// few values we need ourselves. Each instance (main, worktree) has its own
// domain; don't derive from APP_ENV.
let envFileContents = '';
try {
    envFileContents = readFileSync(resolve(__dirname, '.env'), 'utf8');
} catch (e) {
    if (e.code !== 'ENOENT') {
        throw e;
    }
}

function readEnv(key, fallback = '') {
    if (process.env[key]) {
        return process.env[key];
    }
    const match = new RegExp(`^${key}=(.+)$`, 'm').exec(envFileContents);
    return match ? stripWrappingQuotes(match[1].trim()) : fallback;
}

function stripWrappingQuotes(value) {
    const isDoubleQuoted = value.startsWith('"') && value.endsWith('"');
    const isSingleQuoted = value.startsWith("'") && value.endsWith("'");

    return isDoubleQuoted || isSingleQuoted ? value.slice(1, -1) : value;
}

const frontendDomain = readEnv('FRONTEND_DOMAIN', 'local.blb.lara');
const viteNoRefresh = readEnv('VITE_NO_REFRESH', '') !== '';
const viteThemeDir = readEnv('VITE_THEME_DIR', '');

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/app.css', 'resources/core/js/app.js'],
            // Set VITE_NO_REFRESH=1 in local .env to disable auto-reload on file
            // changes (per-machine workaround for chokidar firing phantom mtime
            // events — e.g. Defender/indexing churn on Windows).
            refresh: viteNoRefresh ? false : [
                'resources/core/views/**/*.blade.php',
                'resources/core/css/**/*.css',
                'resources/core/js/**/*.{js,ts,vue}',
                ...(viteThemeDir ? [
                    `resources/extensions/${viteThemeDir}/views/**/*.blade.php`,
                    `resources/extensions/${viteThemeDir}/css/**/*.css`,
                    `resources/extensions/${viteThemeDir}/js/**/*.{js,ts,vue}`,
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
