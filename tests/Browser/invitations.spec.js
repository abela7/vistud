import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { foreignColours, openAccounts, THEMES, useSentinelTheme, useTheme } from './support.js';

/*
| Invitations (ADR 0003 D4, PM decision Q3): an admin makes a single-use
| link on the Accounts page and shares it; the invited person opens it,
| chooses a name and password, and lands in ViStud logged in.
*/

const desktop = { width: 1440, height: 900 };
const phone = { width: 390, height: 844 };
const inviteDialog = (page) => page.locator('#invite-dialog');
const newEmail = () => `invited-${Date.now()}-${Math.random().toString(16).slice(2)}@example.test`;
const analyse = async (page) =>
    (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations.map(
        (v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`,
    );

test.use({ reducedMotion: 'reduce' });
// Each test signs an admin in with a recovery code and a password check first; slower machines need more than the default 30 s.
test.describe.configure({ timeout: 60_000 });

/** From the Accounts page, invite $email and return the link the dialog shows. */
async function invite(page, email) {
    await page.getByRole('button', { name: 'Invite someone' }).click();
    await expect(inviteDialog(page)).toBeVisible();
    await expect(inviteDialog(page).getByLabel('Their email address')).toBeFocused();
    await inviteDialog(page).getByLabel('Their email address').fill(email);
    await inviteDialog(page).getByRole('button', { name: 'Create invitation link' }).click();
    await expect(inviteDialog(page).getByRole('heading', { name: `Invitation for ${email}` })).toBeFocused();

    return inviteDialog(page).getByLabel('Invitation link').inputValue();
}

async function fillAcceptance(page, name) {
    await page.getByLabel('Your name').fill(name);
    await page.getByLabel('Password', { exact: true }).fill('a-long-enough-password');
    await page.getByLabel('Confirm password').fill('a-long-enough-password');
    await page.getByRole('button', { name: 'Create my account' }).click();
}

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`${name}: an admin invites someone, who accepts the link once in another browser`, async ({ page, browser }) => {
        await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.setViewportSize(viewport);
        await openAccounts(page);
        const email = newEmail();

        const link = await invite(page, email);
        expect(link).toMatch(/\/invitation#[A-Za-z0-9]{48}$/);
        await inviteDialog(page).getByRole('button', { name: 'Copy link' }).click();
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(link);
        await inviteDialog(page).getByRole('button', { name: 'Done' }).click();
        await expect(inviteDialog(page)).toBeHidden();
        await expect(page.getByRole('listitem').filter({ hasText: email })).toBeVisible();

        // The invited person, in a browser of their own.
        const guest = await browser.newContext({ viewport, reducedMotion: 'reduce' });
        const them = await guest.newPage();
        await them.goto(link);
        await expect(them.getByRole('heading', { name: 'Welcome to ViStud' })).toBeVisible();
        expect(new URL(them.url()).hash).toBe(''); // the token left the address bar
        await expect(them.getByLabel('Your name')).toBeFocused();

        await them.getByLabel('Your name').fill('Mary Somerville');
        await them.getByLabel('Password', { exact: true }).fill('too-short');
        await them.getByLabel('Confirm password').fill('too-short');
        await them.getByRole('button', { name: 'Create my account' }).click();
        await expect(them.getByLabel('Password', { exact: true })).toHaveAttribute('aria-invalid', 'true');
        await expect(them.getByLabel('Your name')).toHaveValue('Mary Somerville');

        await fillAcceptance(them, 'Mary Somerville');
        await expect(them.getByRole('heading', { name: 'Welcome, Mary' })).toBeVisible();
        await expect(them.getByText(`From now on, log in with ${email}`)).toBeVisible();
        await guest.close();

        // The same link a second time.
        const again = await browser.newContext();
        const twice = await again.newPage();
        await twice.goto(link);
        await fillAcceptance(twice, 'Someone Else');
        await expect(twice.getByRole('heading', { name: "This link doesn't work" })).toBeVisible();
        await again.close();

        await page.reload();
        await expect(page.getByRole('listitem').filter({ hasText: email })).toHaveCount(0);
        await page.getByLabel('Search accounts').fill(email);
        await expect(page.getByRole('button', { name: 'Manage Mary Somerville' })).toBeVisible();
    });
}

test('an admin cancels an invitation after confirming, and its link stops working', async ({ page, browser }) => {
    await page.setViewportSize(desktop);
    await openAccounts(page);
    const email = newEmail();
    const link = await invite(page, email);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Invite someone' })).toBeFocused();

    await page.getByRole('button', { name: `Cancel the invitation for ${email}` }).click();
    await expect(page.getByRole('heading', { name: `Cancel the invitation for ${email}?` })).toBeFocused();
    await page.getByRole('button', { name: 'Cancel invitation', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: `The invitation for ${email} is cancelled.` })).toBeVisible();
    await expect(page.getByRole('listitem').filter({ hasText: email })).toHaveCount(0);

    const guest = await browser.newContext();
    const them = await guest.newPage();
    await them.goto(link);
    await fillAcceptance(them, 'Mary Somerville');
    await expect(them.getByRole('heading', { name: "This link doesn't work" })).toBeVisible();
    await guest.close();
});

test('the invitation page without its token says the link does not work', async ({ page }) => {
    await page.goto('/invitation');
    await expect(page.getByRole('heading', { name: "This link doesn't work" })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create my account' })).toBeHidden();
});

for (const [name, viewport] of Object.entries({ desktop, phone })) {
    test(`every colour in invitations comes from a token: ${name}`, async ({ page, browser }) => {
        await page.setViewportSize(viewport);
        await openAccounts(page);
        await useSentinelTheme(page);
        const states = {};

        await page.getByRole('button', { name: 'Invite someone' }).click();
        await inviteDialog(page).getByLabel('Their email address').fill('not-an-email');
        await inviteDialog(page).getByRole('button', { name: 'Create invitation link' }).click();
        await inviteDialog(page).getByText('Enter an email address').waitFor();
        states['invite form, field error'] = await foreignColours(page);

        const email = newEmail();
        await inviteDialog(page).getByLabel('Their email address').fill(email);
        await inviteDialog(page).getByRole('button', { name: 'Create invitation link' }).click();
        await inviteDialog(page).getByRole('heading', { name: `Invitation for ${email}` }).waitFor();
        const link = await inviteDialog(page).getByLabel('Invitation link').inputValue();
        states['link shown'] = await foreignColours(page);
        await inviteDialog(page).getByRole('button', { name: 'Done' }).click();

        await page.getByRole('button', { name: `Cancel the invitation for ${email}` }).hover();
        states['pending list, cancel hover'] = await foreignColours(page);

        const guest = await browser.newContext({ viewport, reducedMotion: 'reduce' });
        const them = await guest.newPage();
        await them.goto(link);
        await useSentinelTheme(them);
        states['acceptance page'] = await foreignColours(them);
        await them.getByLabel('Password', { exact: true }).fill('short');
        await them.getByRole('button', { name: 'Create my account' }).click();
        await expect(them.getByLabel('Password', { exact: true })).toHaveAttribute('aria-invalid', 'true');
        await useSentinelTheme(them);
        states['acceptance page, field errors'] = await foreignColours(them);
        await them.goto('/invitation');
        await useSentinelTheme(them);
        states['link does not work'] = await foreignColours(them);
        await guest.close();

        for (const [state, colours] of Object.entries(states)) {
            expect(colours, `${state}: colours not from a token`).toEqual([]);
        }
    });
}

for (const theme of THEMES) {
    test(`axe finds no violations in invitations: ${theme}`, async ({ page, browser }) => {
        await page.setViewportSize(desktop);
        await openAccounts(page);
        await useTheme(page, theme);

        await page.getByRole('button', { name: 'Invite someone' }).click();
        await inviteDialog(page).getByRole('button', { name: 'Create invitation link' }).click();
        await inviteDialog(page).getByText('Enter an email address').waitFor();
        expect(await analyse(page)).toEqual([]);

        const email = newEmail();
        const link = await (async () => {
            await inviteDialog(page).getByLabel('Their email address').fill(email);
            await inviteDialog(page).getByRole('button', { name: 'Create invitation link' }).click();
            await inviteDialog(page).getByRole('heading', { name: `Invitation for ${email}` }).waitFor();
            return inviteDialog(page).getByLabel('Invitation link').inputValue();
        })();
        expect(await analyse(page)).toEqual([]);
        await inviteDialog(page).getByRole('button', { name: 'Done' }).click();
        expect(await analyse(page)).toEqual([]);

        const guest = await browser.newContext({ reducedMotion: 'reduce' });
        const them = await guest.newPage();
        for (const viewport of [desktop, phone]) {
            await them.setViewportSize(viewport);
            await them.goto(link);
            await useTheme(them, theme);
            expect(await analyse(them)).toEqual([]);
        }
        await them.goto('/invitation');
        await useTheme(them, theme);
        expect(await analyse(them)).toEqual([]);
        await guest.close();
    });
}

test('the invitation page and the invite dialog never scroll sideways at 320 px, even with 200% text', async ({ page }) => {
    await page.goto('/invitation#some-token');
    for (const zoom of ['100%', '200%']) {
        await page.setViewportSize({ width: 320, height: 800 });
        await page.evaluate((size) => (document.documentElement.style.fontSize = size), zoom);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `invitation page, 320 px at ${zoom} text`).toBeLessThanOrEqual(0);
    }

    await page.evaluate(() => (document.documentElement.style.fontSize = ''));
    await openAccounts(page);
    await page.evaluate(() => (document.documentElement.style.fontSize = '200%'));
    await page.getByRole('button', { name: 'Invite someone' }).click();
    const email = newEmail();
    await inviteDialog(page).getByLabel('Their email address').fill(email);
    await inviteDialog(page).getByRole('button', { name: 'Create invitation link' }).click();
    await inviteDialog(page).getByRole('heading', { name: `Invitation for ${email}` }).waitFor();
    const dialogOverflow = await inviteDialog(page).evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(dialogOverflow, 'the link step at 200% text').toBeLessThanOrEqual(0);
});
