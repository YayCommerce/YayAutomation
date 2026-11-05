import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./specs",
  outputDir: "./test-results",
  // One site: an active workflow would fire on another test's order.
  workers: 1,
  timeout: 90_000,
  reporter: [["list"], ["html", { outputFolder: "./report", open: "never" }]],
  globalSetup: "./support/global-setup.ts",
  use: {
    actionTimeout: 15_000,
    baseURL: `http://localhost:${process.env.WP_ENV_PORT ?? 8910}`,
    storageState: `${__dirname}/.auth/admin.json`,
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
  },
});
