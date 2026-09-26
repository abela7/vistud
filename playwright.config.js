import { defineConfig, devices } from '@playwright/test';

// Browser tests (ADR 0003 §12). They run against `php artisan serve` with
// built assets (`npm run build`). Set PLAYWRIGHT_CHROMIUM_PATH to use an
// already-installed Chromium instead of Playwright's own download.
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined;
const baseURL = process.env.BROWSER_TEST_URL ?? 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: 'tests/Browser',
    outputDir: 'test-results',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL,
        launchOptions: executablePath ? { executablePath } : {},
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'php artisan serve --port=8000',
        url: `${baseURL}/up`,
        reuseExistingServer: true,
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
