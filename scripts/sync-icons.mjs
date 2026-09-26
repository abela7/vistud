// Copies the Lucide icons ViStud uses from lucide-static into
// resources/icons/, so the <x-icon> component can inline them without
// node_modules on the server (DESIGN.md §4.6). Add a name here, then run
// `npm run icons` and commit the result.
import { copyFileSync, readdirSync, rmSync, mkdirSync } from 'node:fs';

const icons = [
    'arrow-left',
    'arrow-left-right',
    'ban',
    'check',
    'chevron-down',
    'circle-alert',
    'circle-check',
    'copy',
    'eye',
    'eye-off',
    'house',
    'info',
    'key-round',
    'layout-dashboard',
    'loader-circle',
    'log-out',
    'mail',
    'menu',
    'monitor',
    'moon',
    'notebook-text',
    'panel-left',
    'scroll-text',
    'shield',
    'shield-check',
    'shield-minus',
    'shield-plus',
    'sun',
    'triangle-alert',
    'user-check',
    'user-plus',
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
