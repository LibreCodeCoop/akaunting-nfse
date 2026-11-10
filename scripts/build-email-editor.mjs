// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { build } from 'esbuild';

await build({
    entryPoints: ['Resources/assets/js/nfse-email-ckeditor.src.js'],
    outdir: 'Resources/assets/vendor/ckeditor5',
    entryNames: 'nfse-email-ckeditor',
    bundle: true,
    minify: true,
    format: 'iife',
    target: ['es2020'],
    legalComments: 'linked',
    logLevel: 'info',
    banner: {
        js: '/* CKEditor 5 (GPL-2.0-or-later) and LibreCode (AGPL-3.0-or-later). See the accompanying LEGAL.txt. */',
        css: '/* CKEditor 5 (GPL-2.0-or-later). See the accompanying LEGAL.txt. */',
    },
});
