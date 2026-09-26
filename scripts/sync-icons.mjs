// Copies the Lucide icons ViStud uses from lucide-static into
// resources/icons/, so the <x-icon> component can inline them without
// node_modules on the server (DESIGN.md §4.6). Add a name here, then run
// `npm run icons` and commit the result.
import { copyFileSync, readdirSync, rmSync, mkdirSync } from 'node:fs';

const icons = [
    'archive',
    'archive-restore',
    'arrow-down',
    'arrow-left',
    'arrow-left-right',
    'arrow-up',
    'ban',
    'bold',
    'book-open',
    'brain',
    'calculator',
    'calendar',
    'check',
    'chevron-down',
    'chevron-right',
    'chevrons-up-down',
    'circle-alert',
    'circle-check',
    'circle-dot',
    'clock',
    'code',
    'copy',
    'ellipsis',
    'eye',
    'eye-off',
    'file-text',
    'file-up',
    'flask-conical',
    'folder',
    'folder-input',
    'folder-plus',
    'globe',
    'grip-vertical',
    'heading-2',
    'house',
    'image',
    'info',
    'italic',
    'key-round',
    'landmark',
    'languages',
    'layers',
    'layout-dashboard',
    'layout-grid',
    'list',
    'loader-circle',
    'log-out',
    'mail',
    'menu',
    'microscope',
    'monitor',
    'moon',
    'music',
    'notebook-text',
    'palette',
    'panel-left',
    'pencil',
    'plus',
    'scroll-text',
    'settings',
    'shield',
    'shield-check',
    'shield-minus',
    'shield-plus',
    'sigma',
    'sun',
    'trash-2',
    'trending-up',
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
