import { defineConfig, devices, LaunchOptions } from "@playwright/test";

/**
 * Read environment variables from file.
 * https://github.com/motdotla/dotenv
 */
require("dotenv").config();

// Whether the target site is a local env (a *.local / *.test host or a loopback
// IP). We only tweak proxy behavior for these. Keep this in sync with the same
// check in tests/site-setup.ts, which decides docker-exec vs SSH provisioning.
const isLocalTarget = (() => {
  try {
    const { hostname } = new URL(process.env.SITE_URL ?? "");
    return (
      hostname.endsWith(".local") ||
      hostname.endsWith(".test") ||
      /^127\.|^localhost$/.test(hostname)
    );
  } catch {
    return false;
  }
})();

// Add a delay on CI, so the video recordings are more readable.
const launchOptions: LaunchOptions = process.env.CI
  ? {
      slowMo: 1000,
    }
  : isLocalTarget
  ? {
      // Bypass any system PAC / proxy auto-config when targeting a local env.
      // macOS often has an org-wide PAC URL that Chromium consults per request,
      // adding ~2s of latency even when the rule resolves to "direct" for local
      // IPs. Scoped to local targets so it can't break proxy-dependent setups
      // that need a proxy to reach the internet.
      args: ["--proxy-server=direct://"],
    }
  : {};

/**
 * See https://playwright.dev/docs/test-configuration.
 */
export default defineConfig({
  testDir: "./tests",
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 1 : 0,
  /* Opt out of parallel tests. */
  workers: 1,
  fullyParallel: false,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: [
    ["list"],
    ["html", { open: process.env.CI ? "never" : "on-failure" }],
    ["junit", { outputFile: "test-results/results.xml" }],
  ],
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    baseURL: process.env.SITE_URL,

    /* Applied to every project (including the setup projects). */
    launchOptions,

    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: process.env.CI ? "on-first-retry" : "retain-on-failure",
    video: "retain-on-failure",
    screenshot: { mode: "only-on-failure", fullPage: true },
  },
  timeout: 120000,
  expect: { timeout: 20000 },
  /* Note that projects depend on each other when provisioning is enabled: the
     vanilla site is set up and its tests run first, then the site is re-provisioned
     with WooCommerce and those tests run. */
  projects: [
    // These two projects provision the site into the state their tests expect.
    // They run a destructive from-scratch rebuild, so they are only included when
    // USE_SETUP is set; without it, `npm test` runs the specs against the site's
    // current state and never re-provisions. Re-provisioning is much slower than
    // the browser actions in a regular test, so give them a generous timeout.
    ...(process.env.USE_SETUP
      ? [
          {
            name: "setup-vanilla",
            testMatch: "vanilla.ts",
            testDir: "./setup",
            timeout: 900000,
          },
          {
            name: "setup-with-woo",
            testMatch: "with-woo.ts",
            testDir: "./setup",
            timeout: 900000,
            dependencies: ["Vanilla in Mobile Chrome"],
          },
        ]
      : []),

    // Vanilla tests.
    {
      name: "Vanilla in Desktop Chrome",
      use: {
        ...devices["Desktop Chrome"],
      },
      grep: /@vanilla/,
      dependencies: process.env.USE_SETUP ? ["setup-vanilla"] : [],
    },
    {
      name: "Vanilla in Mobile Chrome",
      use: {
        ...devices["Pixel 5"],
      },
      grep: /@vanilla/,
      dependencies: process.env.USE_SETUP
        ? ["Vanilla in Desktop Chrome"]
        : [],
    },

    // All tests (will also include Vanilla tests).
    {
      name: "With Woo in Desktop Chrome",
      use: {
        ...devices["Desktop Chrome"],
      },
      grep: /@with-woo/,
      dependencies: process.env.USE_SETUP ? ["setup-with-woo"] : [],
    },
    {
      name: "With Woo in Mobile Chrome",
      use: {
        ...devices["Pixel 5"],
      },
      grep: /@with-woo/,
      dependencies: process.env.USE_SETUP
        ? ["With Woo in Desktop Chrome"]
        : [],
    },
  ],
});
