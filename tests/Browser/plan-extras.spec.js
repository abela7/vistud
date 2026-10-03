/* An assignment's optional boxes (Milestones, Marking criteria, Team): opened from "Also track", closed again while empty, and a milestone deleted (the owner's review, 2026-10-04). */

import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { makeStudentWithModules, openStudentHome } from './support.js';

test('an opened but empty box is closed again, and a milestone is deleted', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const email = makeStudentWithModules();
    const code = [
        `$p = app(\\App\\Identity\\PrincipalFactory::class)->forUser(\\App\\Models\\User::query()->where('email', '${email}')->firstOrFail(), 'web');`,
        `$w = collect(app(\\App\\Study\\Workspaces::class)->list($p))->first(); $a = app(\\App\\Study\\Activities::class);`,
        `$s = $a->create($p, $w->id, ['title' => 'Close test', 'due_on' => now()->addDays(9)->toDateString()]);`,
        `app(\\App\\Study\\Plans::class)->addPart($p, $s->id, 'Part', 50);`,
        `echo route('workspaces.assignments.show', [$w->id, $s->id]);`,
    ].join(' ');
    const url = new URL(execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: process.cwd() }).toString().trim().split('\n').pop()).pathname;
    await openStudentHome(page, email);
    await page.goto(url);
    for (const [tile, heading, close] of [[/^Milestones/, 'Milestones', 'Close milestones'], [/^Marking criteria/, 'Marking criteria', 'Close marking criteria'], [/^Team/, 'Team', 'Close team']]) {
        await page.getByRole('button', { name: tile }).click();
        await expect(page.getByRole('heading', { level: 3, name: heading })).toBeVisible();
        await page.getByRole('button', { name: close }).click();
        await expect(page.getByRole('heading', { level: 3, name: heading })).toHaveCount(0);
        await expect(page.getByRole('button', { name: tile })).toBeVisible();
    }
    await page.getByRole('button', { name: /^Milestones/ }).click();
    await page.getByLabel('Add a milestone').fill('Outline in');
    await page.getByLabel('Add a milestone').press('Enter');
    await expect(page.getByText('Outline in', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Close milestones' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Actions for Outline in' }).click();
    await page.locator('.row-menu:popover-open').getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText('Outline in', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Close milestones' }).click();
    await expect(page.getByRole('button', { name: /^Milestones/ })).toBeVisible();
});
