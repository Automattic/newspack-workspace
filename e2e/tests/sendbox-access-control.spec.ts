import { test, expect } from "@playwright/test";
import { emailSendboxSecret, SENDBOX_SECRET_HEADER } from "./utils";

// Regression guard for the /_email sendbox access control.
//
// The sendbox dumps every captured outgoing email — reader password-reset and
// account-verification links included — and e2e-plugin.php serves it on any
// internet-reachable host provisioned as an e2e target (staging is one). It is
// gated behind a per-run shared secret that provisioning writes to the
// NEWSPACK_E2E_SENDBOX_SECRET constant; a request that does not present a matching
// secret header must be refused before any email content is emitted. These tests
// fail against the pre-fix plugin, which answered every request with 200 and the
// full email dump.
//
// The header name and the secret's local-default fallback are shared with the
// suite's own reader (utils.ts), so a zero-config local run exercises the same
// value provisioning used.
test.describe("Email sendbox access control", () => {
  test("refuses a request with no secret", { tag: "@vanilla" }, async ({ page }) => {
    await page.setExtraHTTPHeaders({});
    const response = await page.goto("/_email");
    expect(response?.status()).toBe(403);
    await expect(page.getByText("Email Sendbox")).toHaveCount(0);
  });

  test("refuses a request with the wrong secret", { tag: "@vanilla" }, async ({ page }) => {
    await page.setExtraHTTPHeaders({ [SENDBOX_SECRET_HEADER]: "not-the-sendbox-secret" });
    const response = await page.goto("/_email");
    expect(response?.status()).toBe(403);
    await expect(page.getByText("Email Sendbox")).toHaveCount(0);
  });

  test("serves the sendbox with the correct secret", { tag: "@vanilla" }, async ({ page }) => {
    await page.setExtraHTTPHeaders({ [SENDBOX_SECRET_HEADER]: emailSendboxSecret() });
    const response = await page.goto("/_email");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { name: "Email Sendbox" })).toBeVisible();
  });

  // Guards the page-cache exclusion (batcache_cancel() in e2e-plugin.php). The
  // secret rides a header, which page caches don't key on, so a stored authorized
  // 200 would otherwise be replayed to an unauthenticated request at the same URL.
  // Batcache stores a page after two accesses, so prime one URL with two authorized
  // loads, then confirm an unauthenticated load of that same URL is still refused
  // rather than served the cached dump. A fresh, unique URL keeps the probe from
  // colliding with a neighbouring run's cache. Without the exclusion this fails with
  // a cached 200; note it can only fail where a page cache is active (it may pass
  // trivially on an uncached target).
  test("does not serve a cached authorized page to an unauthenticated request", { tag: "@vanilla" }, async ({ page }) => {
    const url = `/_email?cache-probe=${Date.now()}`;
    await page.setExtraHTTPHeaders({ [SENDBOX_SECRET_HEADER]: emailSendboxSecret() });
    await page.goto(url);
    await page.goto(url); // Second authorized hit crosses the cache's store threshold.
    await page.setExtraHTTPHeaders({});
    const response = await page.goto(url);
    expect(response?.status()).toBe(403);
    await expect(page.getByText("Email Sendbox")).toHaveCount(0);
  });
});
