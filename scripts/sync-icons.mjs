// Copies the Lucide icons ViStud uses from lucide-static into
// resources/icons/, so the <x-icon> component can inline them without
// node_modules on the server (DESIGN.md §4.6). Add a name here, then run
// `npm run icons` and commit the result.
import { copyFileSync, readdirSync, rmSync, mkdirSync } from 'node:fs';

const icons = [
    'arrow-left-right',
    'check',
    'circle-alert',
    'circle-check',
    'eye',
    'eye-off',
    'info',
    'loader-circle',
    'log-out',
    'monitor',
    'moon',
    'scroll-text',
    'shield',
    'sun',
    'triangle-alert',
    'users',
    'x',
];

const from = 'node_modules/lucide-static/icons';
const to = 'resources/icons';

mkdirSync(to, { recursive: true });
for (const file of readdirSync(to)) {
    if (file.endsWith('.svg')) rmSync(`${to}/${file}`);
}
for (const name of icons) {
    copyFileSync(`${from}/${name}.svg`, `${to}/${name}.svg`);
}
console.log(`Copied ${icons.length} icons to ${to}.`);
