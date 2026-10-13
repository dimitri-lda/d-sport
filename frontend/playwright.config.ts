import { existsSync } from 'node:fs';
import { defineConfig } from '@playwright/test';

/**
 * Browser E2E against the disposable test stack started by scripts/e2e.sh:
 * a one-off backend on an isolated fixture database plus a host Vite server.
 * Scenarios run serially because they share that database. A system
 * Chromium-family binary is used via E2E_CHROMIUM (no browser download —
 * the first existing candidate wins); screenshots and traces are kept only
 * for failures and never checked in.
 */
const chromiumCandidates = [
  process.env.E2E_CHROMIUM,
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/opt/homebrew/bin/chromium',
].filter((path): path is string => typeof path === 'string' && path !== '');
const executablePath = chromiumCandidates.find((path) => existsSync(path));

export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173',
    launchOptions: executablePath === undefined ? {} : { executablePath },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
