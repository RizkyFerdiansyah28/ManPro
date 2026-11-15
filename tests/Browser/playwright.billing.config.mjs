import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';

const run = process.env.SILANSIA_BROWSER_RUN ?? 'FINAL';
if (!/^[A-Z0-9_]+$/.test(run)) throw new Error('Invalid evidence run identifier');
export default defineConfig({
    testDir: '.', testMatch: 'billingRecovery.spec.mjs', fullyParallel: false, workers: 1,
    retries: 0, timeout: 20000, expect: { timeout: 5000 },
    outputDir: `../../docs/report/SILANSIA_P9_3C3B_BROWSER_ARTIFACTS_${run}`,
    reporter: [['list'], ['json', { outputFile: fileURLToPath(new URL(`../../docs/report/SILANSIA_P9_3C3B_BROWSER_RESULTS_${run}.json`, import.meta.url)) }]],
    use: { channel: 'chrome', headless: true, launchOptions: { chromiumSandbox: true },
        viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block', trace: 'off', video: 'off', screenshot: 'off' },
});
