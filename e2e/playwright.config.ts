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

// The suite provisions the target site from scratch and runs in two phases -- a
// vanilla site, then the same site re-provisioned with WooCommerce -- across two
// viewports (desktop + mobile). End to end that is well over TeamCity's 20-minute
// per-build execution cap, so CI slices it: E2E_PHASE (vanilla | woo) and
// E2E_VIEWPORT (desktop | mobile) each pick one axis, and every slice provisions
// and runs on its own, so the four combinations can run as separate parallel
// builds -- each against its own site, since each provisioning does a destructive
// from-scratch reset that would clobber a site another slice is using. Leaving
// both unset runs the whole thing against a single site, which is what a local
// `USE_SETUP` run wants.
const phase = (process.env.E2E_PHASE ?? "both").toLowerCase();
const viewport = (process.env.E2E_VIEWPORT ?? "both").toLowerCase();
const runVanilla = phase !== "woo";
const runWoo = phase !== "vanilla";
const runDesktop = viewport !== "mobile";
const runMobile = viewport !== "desktop";
const useSetup = !!process.env.USE_SETUP;

const desktop = { ...devices["Desktop Chrome"] };
const mobile = { ...devices["Pixel 5"] };

// Build the project list for the selected phase/viewport slice. Dependencies are
// wired so each slice is self-contained: a setup project provisions the site,
// then the spec projects for that phase run against it. When both viewports of a
// phase run on one site (no viewport slice), the mobile project is sequenced after
// desktop so the two never race on the shared site (workers = 1); when only one
// viewport runs, it depends on the setup directly. The woo phase re-provisions the
// site, so its setup follows the last vanilla project when both phases share a
// site, and provisions directly (no vanilla dependency) when the woo phase is
// sliced onto its own site. Without USE_SETUP no provisioning runs and the
// selected spec projects execute against the site's current state.
const buildProjects = () => {
  const projects = [];
  const lastVanilla = runMobile
    ? "Vanilla in Mobile Chrome"
    : "Vanilla in Desktop Chrome";

  if (useSetup && runVanilla) {
    projects.push({
      name: "setup-vanilla",
      testMatch: "vanilla.ts",
      testDir: "./setup",
      timeout: 900000,
    });
  }
  if (runVanilla && runDesktop) {
    projects.push({
      name: "Vanilla in Desktop Chrome",
      use: desktop,
      grep: /@vanilla/,
      dependencies: useSetup ? ["setup-vanilla"] : [],
    });
  }
  if (runVanilla && runMobile) {
    projects.push({
      name: "Vanilla in Mobile Chrome",
      use: mobile,
      grep: /@vanilla/,
      dependencies: useSetup
        ? [runDesktop ? "Vanilla in Desktop Chrome" : "setup-vanilla"]
        : [],
    });
  }

  if (useSetup && runWoo) {
    projects.push({
      name: "setup-with-woo",
      testMatch: "with-woo.ts",
      testDir: "./setup",
      timeout: 900000,
      dependencies: runVanilla ? [lastVanilla] : [],
    });
  }
  if (runWoo && runDesktop) {
    projects.push({
      name: "With Woo in Desktop Chrome",
      use: desktop,
      grep: /@with-woo/,
      dependencies: useSetup ? ["setup-with-woo"] : [],
    });
  }
  if (runWoo && runMobile) {
    projects.push({
      name: "With Woo in Mobile Chrome",
      use: mobile,
      grep: /@with-woo/,
      dependencies: useSetup
        ? [runDesktop ? "With Woo in Desktop Chrome" : "setup-with-woo"]
        : [],
    });
  }

  return projects;
};

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
  /* The project list is sliced by E2E_PHASE / E2E_VIEWPORT -- see buildProjects
     above. Projects depend on each other when provisioning is enabled: the site
     is set up and its tests run first, then (for a shared-site run) it is
     re-provisioned with WooCommerce and those tests run. */
  projects: buildProjects(),
});
