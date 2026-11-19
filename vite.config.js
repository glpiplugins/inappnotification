/**
 * -------------------------------------------------------------------------
 * InAppNotification plugin for GLPI
 *
 * https://github.com/glpiplugins/inappnotification
 *
 * @copyright 2026 Roy Brannath
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of InAppNotification.
 *
 * InAppNotification is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * InAppNotification is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with InAppNotification. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import fs from 'node:fs';
import path from 'node:path';

// Licence header of the built files, in the format of GLPI's licence header check
// (bin/console tools:licence_headers_check): "/**" for scripts, "/*!" for stylesheets.
const headerLines = fs.readFileSync(path.resolve(__dirname, '.licence-header'), 'utf8')
    .trimEnd()
    .split('\n')
    .map((line) => ` * ${line}`.trimEnd());
const licenceHeader = (opening) => [opening, ...headerLines, ' */', '', ''].join('\n');

const licenceBanner = {
    name: 'licence-banner',
    enforce: 'post',
    generateBundle(_options, bundle) {
        Object.values(bundle).forEach((file) => {
            if (file.type === 'chunk') {
                file.code = licenceHeader('/**') + file.code;
            } else if (file.fileName.endsWith('.css')) {
                file.source = licenceHeader('/*!') + file.source;
            }
        });
    },
};

// The bell UI is a Vue 3 component, but GLPI already ships a Vue runtime on every
// central page (build/vue/app.js -> window._vue / window.Vue). We therefore mark
// `vue` as external and map it to the global `window._vue`, so this bundle reuses
// GLPI's Vue instance instead of bundling a second copy.
//
// The bundle is loaded via $PLUGIN_HOOKS['javascript_module'] (footer), which runs
// AFTER the head's app.js module, so `window._vue` is guaranteed to exist when the
// IIFE captures it. SFC templates are precompiled here (no runtime compiler -> no
// 'unsafe-eval' needed -> CSP-safe).
export default defineConfig({
    plugins: [vue(), licenceBanner],
    // The sources' licence headers are dropped from the bundle, which gets a single one.
    esbuild: { legalComments: 'none' },
    publicDir: false,
    define: {
        __VUE_OPTIONS_API__: false,
        __VUE_PROD_DEVTOOLS__: false,
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
    },
    build: {
        emptyOutDir: true,
        outDir: path.resolve(__dirname, 'public/js/dist'),
        cssCodeSplit: false,
        sourcemap: false,
        lib: {
            entry: path.resolve(__dirname, 'src/vue/main.js'),
            name: 'InappnotificationBell',
            formats: ['iife'],
            fileName: () => 'inappnotification-vue.js',
        },
        rollupOptions: {
            external: ['vue'],
            output: {
                globals: { vue: 'window._vue' },
                // Stable name for the extracted stylesheet (loaded via add_css).
                assetFileNames: 'inappnotification-vue[extname]',
            },
        },
    },
});
