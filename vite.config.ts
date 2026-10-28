import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

const vitePort = Number(process.env.VITE_PORT ?? 9456);

// Which host gets written into public/hot (every asset tag on the page
// resolves against this). Defaults to localhost for normal PC browsing; set
// VITE_HMR_HOST to a Tailscale IP and restart vite-server to browse from
// another device instead - the two don't work at the same time, since a
// machine generally can't reach its own Tailscale/public IP (NAT hairpin).
const hmrHost = process.env.VITE_HMR_HOST ?? 'localhost';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        host: '0.0.0.0',
        port: vitePort,
        strictPort: true,
        // laravel-vite-plugin writes this host into public/hot, which is
        // what every asset <script>/<link> tag on the page resolves against
        // - not just the HMR socket. It can't be "0.0.0.0" (not a connectable
        // address) or left unset (falls back to the literal server.host
        // value, i.e. "0.0.0.0" again).
        hmr: {
            host: hmrHost,
            clientPort: vitePort,
        },
        // Keep Vite's Host-header allowlist on (avoids DNS rebinding), just
        // extended with this machine's Tailscale IP so other tailnet devices
        // can reach the dev server too.
        allowedHosts: ['localhost', '127.0.0.1', '100.73.207.63'],
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/prompts/**',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
