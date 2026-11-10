import { defineConfig, devices } from '@playwright/test'

/*
 * Nur fuer die Entwicklung: prueft eine echte TYPO3-Installation von aussen, so wie ein Redakteur sie
 * sieht, der mit `publishpage:setup` angelegt wurde. Die CI baut dafuer je Szenario eine frische
 * Installation, siehe .github/workflows/ci.yml (Job "installation").
 *
 * PUBLISHPAGE_BASE_URL zeigt auf die Installation, zum Beispiel https://mein-projekt.ddev.site.
 */
export default defineConfig({
  testDir: './Tests/Playwright',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.PUBLISHPAGE_BASE_URL ?? 'http://127.0.0.1:8080',
    trace: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'installation',
      testMatch: /installation\/.*\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
