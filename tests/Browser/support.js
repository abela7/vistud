import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const sentinel = JSON.parse(readFileSync(new URL('./fixtures/sentinel-theme.json', import.meta.url)));

export const THEMES = ['vistud-light', 'vistud-dark', 'ember'];

/** Switch the theme in place, as a saved preset or custom theme would, without reloading. */
export async function useTheme(page, theme) {
    // Checks must see the styled page: a heading can be visible before the stylesheet has loaded.
    await page.waitForLoadState('load');
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
    await page.waitForLoadState('load');
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

/** An account in the app database the browser server uses. */
function makeAccount(twoFactor, admin = false, name = null) {
    const email = `browser-${Date.now()}-${Math.random().toString(16).slice(2)}@example.test`;
    const php = process.env.PHP_BINARY || 'php';
    const factory = `\\App\\Models\\User::factory()->student()${admin ? '->admin()' : ''}${twoFactor ? '->twoFactor()' : ''}`;
    const attributes = `['email' => '${email}'${name ? `, 'name' => '${name}'` : ''}]`;
    const code = `if (!\\App\\Models\\User::query()->where('email', '${email}')->exists()) { ${factory}->create(${attributes}); }`;
    execFileSync(php, ['artisan', 'tinker', '--execute', code], { cwd: appRoot, stdio: 'pipe' });

    return email;
}

/** A confirmed two-factor account in the app database the browser server uses. */
export function makeTwoFactorAccount() {
    return makeAccount(true);
}

/** A student with a given name (letters and spaces only), for screens that show it. */
export function makeNamedStudent(name) {
    return makeAccount(false, false, name);
}

/** A student with no second factor, so login finishes on the home page. */
export function makeStudentAccount() {
    return makeAccount(false);
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

/** Sign in as a student and open the password confirmation screen. */
export async function openConfirmPassword(page, email = makeStudentAccount()) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.getByRole('heading', { name: /^Welcome back/ }).waitFor();
    await page.goto('/user/confirm-password');
    await page.waitForURL('**/user/confirm-password');

    return email;
}

/** The current 6-digit code for a base32 setup key (RFC 6238, as authenticator apps compute it). */
export function totp(setupKey, now = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of setupKey.replace(/\s+/g, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(now / 30000)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const value = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000;

    return String(value).padStart(6, '0');
}

/** Sign in as a new student and open the two-factor setup, confirming the password on the way. */
export async function openTwoFactorSetup(page, email = makeStudentAccount()) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.getByRole('heading', { name: /^Welcome back/ }).waitFor();
    await page.getByRole('link', { name: 'Set up' }).click();
    await page.waitForURL('**/user/confirm-password');
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.waitForURL('**/user/two-factor');

    return email;
}

/** From the setup screen's "off" state, turn it on and wait for the QR code. */
export async function startTwoFactorSetup(page) {
    await page.getByRole('button', { name: 'Turn on two-factor authentication' }).click();
    await page.getByRole('heading', { name: 'Set up your authenticator app' }).waitFor();
    await page.waitForLoadState('load');

    return (await page.locator('#setup-key').textContent()).trim();
}

/** Log in as a new admin with 2FA (using a recovery code), confirm the password, and open the admin overview. */
export async function openAdminOverview(page) {
    const email = makeAccount(true, true);
    await loginToChallenge(page, email);
    await page.getByRole('button', { name: 'Use a recovery code instead' }).click();
    await page.getByLabel('Recovery code').fill('code-one-aaaa');
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.getByRole('link', { name: 'Admin area' }).click();
    await page.waitForURL('**/user/confirm-password');
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.waitForURL('**/admin');

    return email;
}

/** Log in as a new student and wait on the home page, inside the signed-in frame. */
export async function openStudentHome(page, email = makeStudentAccount()) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password-for-tests');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.getByRole('heading', { name: /^Welcome back/ }).waitFor();
    await page.waitForLoadState('load');

    return email;
}

/** Log in as a new admin, open the Accounts page, and search for $email if given. Returns the admin's email. */
export async function openAccounts(page, email = null) {
    const admin = await openAdminOverview(page);
    await page.goto('/admin/accounts');
    await page.getByRole('heading', { name: 'Accounts', exact: true }).waitFor();
    await page.waitForLoadState('load');
    if (email) {
        await page.getByLabel('Search accounts').fill(email);
        await page.getByText('1 match', { exact: true }).waitFor();
    }

    return admin;
}
