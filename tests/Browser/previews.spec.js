import { test } from '@playwright/test';
import { makeNamedStudent, openAccounts, openAdminOverview, openStudentHome, openTwoFactorSetup, startTwoFactorSetup, totp, loginToChallenge, openConfirmPassword, useTheme } from './support.js';

/*
| Screenshots for UI handoff and PM visual review (DESIGN.md §10).
| Run with PREVIEWS=1; they are written to docs/design/previews/.
*/

test.skip(!process.env.PREVIEWS, 'Set PREVIEWS=1 to regenerate the review screenshots.');
test.use({ reducedMotion: 'reduce' });

const out = (name) => `docs/design/previews/${name}.png`;
const sizes = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } };

for (const theme of ['vistud-light', 'vistud-dark', 'ember']) {
    for (const [size, viewport] of Object.entries(sizes)) {
        test(`login ${size} ${theme}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await page.goto('/login');
            await useTheme(page, theme);
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`login-${size}-${theme}`), fullPage: size === 'mobile' });
        });
    }
}

for (const theme of ['vistud-light', 'vistud-dark']) {
    for (const [size, viewport] of Object.entries(sizes)) {
        test(`two-factor challenge ${size} ${theme}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await loginToChallenge(page);
            await useTheme(page, theme);
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`two-factor-${size}-${theme}`), fullPage: size === 'mobile' });
        });
    }
}

for (const theme of ['vistud-light', 'vistud-dark']) {
    for (const [size, viewport] of Object.entries(sizes)) {
        test(`confirm password ${size} ${theme}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await openConfirmPassword(page);
            await useTheme(page, theme);
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`confirm-password-${size}-${theme}`), fullPage: size === 'mobile' });
        });
    }
}

test('confirm password state: wrong password', async ({ page }) => {
    await page.setViewportSize(sizes.desktop);
    await openConfirmPassword(page);
    await page.getByLabel('Password', { exact: true }).fill('not-the-password');
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.waitForURL('**/user/confirm-password');
    await page.screenshot({ path: out('confirm-password-desktop-vistud-light-wrong-password') });
});

test('two-factor challenge states: wrong code and recovery code', async ({ page }) => {
    await page.setViewportSize(sizes.desktop);
    await loginToChallenge(page);
    await page.getByLabel('Authentication code').fill('000000');
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.waitForURL('**/two-factor-challenge');
    await page.screenshot({ path: out('two-factor-desktop-vistud-light-wrong-code') });

    await page.setViewportSize(sizes.mobile);
    await loginToChallenge(page);
    await useTheme(page, 'vistud-dark');
    await page.getByRole('button', { name: 'Use a recovery code instead' }).click();
    await page.evaluate(() => document.activeElement?.blur());
    await page.screenshot({ path: out('two-factor-mobile-vistud-dark-recovery'), fullPage: true });
});

test('login states: refused login and field errors', async ({ page }) => {
    await page.setViewportSize(sizes.desktop);
    await page.goto('/login');
    await page.getByLabel('Email').fill('ada@example.test');
    await page.getByLabel('Password', { exact: true }).fill('not-the-password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/login');
    await page.screenshot({ path: out('login-desktop-vistud-light-refused') });

    await page.setViewportSize(sizes.mobile);
    await page.goto('/login');
    await useTheme(page, 'vistud-dark');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/login');
    await useTheme(page, 'vistud-dark');
    await page.screenshot({ path: out('login-mobile-vistud-dark-field-errors'), fullPage: true });
});

test('login states: focus, hover and loading', async ({ page }) => {
    await page.setViewportSize(sizes.desktop);
    await page.goto('/login');
    await page.getByLabel('Email').fill('ada@example.test');
    await page.keyboard.press('Tab');
    await page.getByLabel('Password', { exact: true }).fill('a-password');
    await page.getByLabel('Keep me logged in on this device').check();
    await page.keyboard.press('Tab'); // from the checkbox to the button, by keyboard, so the focus ring shows
    await page.screenshot({ path: out('login-desktop-vistud-light-focus'), clip: { x: 520, y: 80, width: 920, height: 740 } });

    await page.evaluate(() => document.querySelector('button[type="submit"]').setAttribute('aria-busy', 'true'));
    await page.evaluate(() => document.activeElement?.blur());
    await page.screenshot({ path: out('login-desktop-vistud-light-loading'), clip: { x: 520, y: 80, width: 920, height: 740 } });
});

test('logo treatments on every theme', async ({ page }) => {
    await page.setViewportSize({ width: 1200, height: 900 });
    await page.goto('/login');
    // The same components under three theme scopes: [data-theme] works on any element.
    await page.evaluate(() => {
        const row = (theme) => `
            <section data-theme="${theme}" class="grid grid-cols-3 gap-4 bg-canvas p-6 text-fg">
                <p class="col-span-3 text-sm font-semibold">${theme}</p>
                <div class="grid h-40 place-items-center rounded-xl border border-border bg-surface">
                    <span data-logo-surface></span><span class="text-xs text-fg-muted">On a surface</span>
                </div>
                <div class="surface-brand grid h-40 place-items-center rounded-xl">
                    <span data-logo-knockout></span><span class="text-xs text-fg-muted">On the brand gradient</span>
                </div>
                <div class="surface-header grid h-40 place-items-center rounded-xl">
                    <span data-mark-knockout></span><span class="text-xs text-fg-muted">Mark, on a header</span>
                </div>
            </section>`;
        document.body.innerHTML = ['vistud-light', 'vistud-dark', 'ember'].map(row).join('');
        const logo = (file, plate) => `${plate ? '<span class="logo-plate">' : ''}<img src="/brand/${file}" alt="" style="height:44px;width:auto">${plate ? '</span>' : ''}`;
        document.querySelectorAll('[data-logo-surface]').forEach((el) => (el.outerHTML = logo('vistud-logo.png', true)));
        document.querySelectorAll('[data-logo-knockout]').forEach((el) => (el.outerHTML = logo('vistud-logo-white.png', false)));
        document.querySelectorAll('[data-mark-knockout]').forEach((el) => (el.outerHTML = logo('vistud-mark-white.png', false)));
    });
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: out('logo-treatments'), fullPage: true });
});

test('login tablet vistud-light', async ({ page }) => {
    await page.setViewportSize({ width: 834, height: 1194 });
    await page.goto('/login');
    await page.evaluate(() => document.activeElement?.blur());
    await page.screenshot({ path: out('login-tablet-vistud-light') });
});

for (const theme of ['vistud-light', 'vistud-dark']) {
    for (const [size, viewport] of Object.entries(sizes)) {
        test(`forgot password ${size} ${theme}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await page.goto('/forgot-password');
            await useTheme(page, theme);
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`forgot-password-${size}-${theme}`), fullPage: size === 'mobile' });
        });

        test(`reset password ${size} ${theme}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await page.goto('/reset-password/not-a-real-token?email=ada@example.test');
            await useTheme(page, theme);
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`reset-password-${size}-${theme}`), fullPage: size === 'mobile' });
        });
    }
}

test('live theme switch recording', async ({ browser }) => {
    const size = { width: 1280, height: 800 };
    const context = await browser.newContext({ viewport: size, reducedMotion: 'reduce', colorScheme: 'light', recordVideo: { dir: 'test-results/video', size } });
    const page = await context.newPage();
    await page.goto('/login');
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(800);
    for (const mode of ['Dark', 'Light', 'Dark', 'System']) {
        await page.getByText(mode, { exact: true }).click();
        await page.waitForTimeout(900);
    }
    await useTheme(page, 'ember');
    await page.waitForTimeout(1200);
    await useTheme(page, 'vistud-light');
    await page.waitForTimeout(600);
    const video = page.video();
    await context.close();
    await video.saveAs('docs/design/previews/theme-switch.webm');
});

test('two-factor setup: off, QR code, recovery codes, on', async ({ page }) => {
    for (const [size, viewport] of Object.entries(sizes)) {
        for (const theme of ['vistud-light', 'vistud-dark']) {
            await page.setViewportSize(viewport);
            await openTwoFactorSetup(page);
            await useTheme(page, theme);
            await page.screenshot({ path: out(`two-factor-setup-${size}-${theme}-off`), fullPage: size === 'mobile' });

            const key = await startTwoFactorSetup(page);
            await useTheme(page, theme);
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`two-factor-setup-${size}-${theme}-qr`), fullPage: size === 'mobile' });

            await page.getByLabel('Authentication code').fill(totp(key));
            await page.getByRole('button', { name: 'Confirm' }).click();
            await page.getByRole('heading', { name: 'Save your recovery codes' }).waitFor();
            await useTheme(page, theme);
            await page.screenshot({ path: out(`two-factor-setup-${size}-${theme}-codes`), fullPage: size === 'mobile' });

            await page.context().clearCookies();
        }
    }
});

test('admin overview', async ({ page }) => {
    for (const [size, viewport] of Object.entries(sizes)) {
        for (const theme of ['vistud-light', 'vistud-dark']) {
            await page.setViewportSize(viewport);
            await openAdminOverview(page);
            await useTheme(page, theme);
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`admin-overview-${size}-${theme}`), fullPage: size === 'mobile' });
            await page.context().clearCookies();
        }
    }
});

test('app shell: top bar, sidebar, drawer and account menu', async ({ page }) => {
    const shellSizes = { ...sizes, tablet: { width: 820, height: 1180 } };

    await openStudentHome(page);
    for (const theme of ['vistud-light', 'vistud-dark']) {
        await useTheme(page, theme);
        for (const [size, viewport] of Object.entries(shellSizes)) {
            await page.setViewportSize(viewport);
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`shell-home-${size}-${theme}`) });

            if (size === 'desktop') {
                await page.locator('[data-menu-button]').click();
                await page.screenshot({ path: out(`shell-account-menu-${theme}`) });
                await page.keyboard.press('Escape');
            } else {
                await page.getByRole('button', { name: 'Open menu' }).click();
                await page.screenshot({ path: out(`shell-drawer-${size}-${theme}`) });
                await page.keyboard.press('Escape');
            }
        }
    }

    await page.setViewportSize(sizes.desktop);
    await useTheme(page, 'vistud-light');
    await page.getByRole('button', { name: 'Collapse sidebar' }).click();
    await page.evaluate(() => document.activeElement?.blur());
    await page.screenshot({ path: out('shell-home-desktop-vistud-light-collapsed') });
    await page.getByRole('button', { name: 'Expand sidebar' }).click();
});

test('admin accounts: list, account dialog, confirmation', async ({ page }) => {
    const email = makeNamedStudent('Mary Somerville');
    await openAccounts(page);
    for (const [size, viewport] of Object.entries(sizes)) {
        for (const theme of ['vistud-light', 'vistud-dark']) {
            await page.setViewportSize(viewport);
            await useTheme(page, theme);
            await page.getByLabel('Search accounts').fill('');
            await page.locator('#accounts-heading', { hasNotText: 'match' }).waitFor();
            await page.evaluate(() => document.activeElement?.blur());
            await page.screenshot({ path: out(`admin-accounts-${size}-${theme}`) });

            await page.getByLabel('Search accounts').fill(email);
            await page.getByText('1 match', { exact: true }).waitFor();
            await page.getByRole('button', { name: 'Manage Mary Somerville' }).click();
            await page.locator('#account-dialog[open]').waitFor();
            await page.screenshot({ path: out(`admin-accounts-${size}-${theme}-dialog`) });

            await page.locator('#account-dialog').getByRole('button', { name: 'Suspend account' }).click();
            await page.getByRole('heading', { name: 'Suspend Mary Somerville?' }).waitFor();
            await page.screenshot({ path: out(`admin-accounts-${size}-${theme}-confirm`) });
            await page.keyboard.press('Escape');
        }
    }
});

test('invitations: invite dialog, pending list, acceptance page', async ({ page, browser }) => {
    const email = `mary.somerville-${Date.now()}@example.test`;
    await openAccounts(page);
    await page.getByRole('button', { name: 'Invite someone' }).click();
    await page.locator('#invite-dialog').getByLabel('Their email address').fill(email);
    for (const [size, viewport] of Object.entries(sizes)) {
        await page.setViewportSize(viewport);
        await page.screenshot({ path: out(`invite-dialog-${size}-vistud-light-form`) });
    }
    await page.locator('#invite-dialog').getByRole('button', { name: 'Create invitation link' }).click();
    await page.getByRole('heading', { name: `Invitation for ${email}` }).waitFor();
    const link = await page.locator('#invite-link').inputValue();
    for (const [size, viewport] of Object.entries(sizes)) {
        for (const theme of ['vistud-light', 'vistud-dark']) {
            await page.setViewportSize(viewport);
            await useTheme(page, theme);
            await page.screenshot({ path: out(`invite-dialog-${size}-${theme}-link`) });
        }
    }
    await page.locator('#invite-dialog').getByRole('button', { name: 'Done' }).click();
    await page.setViewportSize(sizes.desktop);
    await useTheme(page, 'vistud-light');
    await page.evaluate(() => document.activeElement?.blur());
    await page.screenshot({ path: out('admin-accounts-desktop-vistud-light-pending') });

    const guest = await browser.newContext({ reducedMotion: 'reduce' });
    const them = await guest.newPage();
    for (const [size, viewport] of Object.entries(sizes)) {
        for (const theme of ['vistud-light', 'vistud-dark']) {
            await them.setViewportSize(viewport);
            await them.goto(link);
            await useTheme(them, theme);
            await them.evaluate(() => document.activeElement?.blur());
            await them.screenshot({ path: out(`accept-invitation-${size}-${theme}`), fullPage: size === 'mobile' });
        }
    }
    const fresh = await guest.newPage(); // a new tab, without the token kept by the first
    await fresh.setViewportSize(sizes.mobile);
    await fresh.goto('/invitation');
    await fresh.screenshot({ path: out('accept-invitation-mobile-vistud-light-invalid'), fullPage: true });
    await guest.close();
});
