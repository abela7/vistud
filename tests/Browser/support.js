import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const sentinel = JSON.parse(readFileSync(new URL('./fixtures/sentinel-theme.json', import.meta.url)));

export const THEMES = ['vistud-light', 'vistud-dark', 'ember'];

/** Switch the theme in place, as a saved preset or custom theme would, without reloading. */
export async function useTheme(page, theme) {
    await page.evaluate((id) => {
        document.documentElement.dataset.theme = id;
    }, theme);
}

/**
 * Inject the sentinel theme (ADR 0003 §6.3): every token and gradient stop
 * has its own colour. Call page.emulateMedia({ reducedMotion: 'reduce' })
 * first, so colour transitions finish at once.
 */
export async function useSentinelTheme(page) {
    await page.addStyleTag({ content: sentinel.css });
    await useTheme(page, 'sentinel');
}

/**
 * Every colour the page computes: each element and its ::before, ::after
 * and ::placeholder, across every colour-bearing property, gradients and
 * shadows included. Returns the colours that aren't sentinel values.
 */
export async function foreignColours(page) {
    return page.evaluate((allowed) => {
        const allowedSet = new Set(allowed);
        const properties = [
            'color', 'background-color', 'background-image',
            'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
            'outline-color', 'text-decoration-color', 'caret-color', 'column-rule-color',
            'box-shadow', 'text-shadow', 'accent-color', 'scrollbar-color',
            '-webkit-tap-highlight-color', '-webkit-text-fill-color', '-webkit-text-stroke-color', 'text-emphasis-color',
        ];
        const svgPaint = new Set(['stop', 'feflood', 'fediffuselighting', 'fespecularlighting', 'fedropshadow']);
        const found = new Set();
        const check = (element, pseudo) => {
            const style = getComputedStyle(element, pseudo);
            if (pseudo && (style.content === 'none' || style.content === 'normal') && pseudo !== '::placeholder') return;
            // SVG paint only matters on SVG elements; every HTML element computes a default black fill.
            const props = element instanceof SVGElement
                ? [...properties, 'fill', 'stroke', ...(svgPaint.has(element.localName) ? ['stop-color', 'flood-color', 'lighting-color'] : [])]
                : properties;
            for (const property of props) {
                const value = style.getPropertyValue(property);
                for (const match of value.matchAll(/rgba?\(([^)]*)\)|color\([^)]*\)/g)) {
                    if (!match[1]) {
                        found.add(`${property}: ${match[0]}`);
                        continue;
                    }
                    const parts = match[1].split(/[\s,/]+/).filter(Boolean);
                    const alpha = parts.length > 3 ? parseFloat(parts[3]) : 1;
                    if (alpha === 0) continue; // transparent
                    const rgb = parts.slice(0, 3).join(', ');
                    if (!allowedSet.has(rgb)) {
                        const where = element.id ? `#${element.id}` : element.className?.baseVal ?? element.className ?? element.localName;
                        found.add(`${element.localName}${pseudo ?? ''} (${String(where).slice(0, 40)}) ${property}: ${match[0]}`);
                    }
                }
            }
        };
        for (const element of document.querySelectorAll('*')) {
            if (element.closest('head')) continue;
            check(element, null);
            check(element, '::before');
            check(element, '::after');
            if (element.matches('input, textarea')) check(element, '::placeholder');
        }
        return [...found];
    }, sentinel.colors);
}

/** A mark that survives only while the page is not reloaded. */
export async function markPage(page) {
    await page.evaluate(() => {
        window.__vistudNoReload = true;
    });
}

export async function wasReloaded(page) {
    return page.evaluate(() => window.__vistudNoReload !== true);
}

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** A confirmed two-factor account in the app database the browser server uses. */
export function makeTwoFactorAccount() {
    const email = `2fa-${Date.now()}-${Math.random().toString(16).slice(2)}@example.test`;
    const php = process.env.PHP_BINARY || 'php';
    const code = [
        `if (!\\App\\Models\\User::query()->where('email', '${email}')->exists()) {`,
        `\\App\\Models\\User::factory()->student()->twoFactor()->create(['email' => '${email}']);`,
        '}',
    ].join(' ');
    execFileSync(php, ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' });

    return email;
}

/** Log in as a confirmed two-factor account and wait on the challenge screen. */
export async function loginToChallenge(page, email = makeTwoFactorAccount()) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/two-factor-challenge');

    return email;
}
