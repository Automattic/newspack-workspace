import { test, expect } from "@playwright/test";

// Regression guard for the /_email sendbox access control (NPPM-3067).
//
// The sendbox dumps every captured outgoing email — reader password-reset and
// account-verification links included — and e2e-plugin.php serves it on any
// internet-reachable host that is provisioned as an e2e target (staging is one).
// It is gated behind a per-run shared secret that provisioning writes to the
// NEWSPACK_E2E_SENDBOX_SECRET constant; a request that does not present a
// matching `secret` must be refused before any email content is emitted. These
// tests fail against the pre-fix plugin, which answered every request with 200
// and the full email dump.
//
// The secret mirrors the fallback in utils.ts / e2e-setup.sh, so a local run with
// no E2E_EMAIL_SENDBOX_SECRET set exercises the same value provisioning used.
const sendboxSecret = process.env.E2E_EMAIL_SENDBOX_SECRET || "newspack-e2e-local";

test.describe("Email sendbox access control", () => {
  test("refuses a request with no secret", { tag: "@vanilla" }, async ({ page }) => {
    const response = await page.goto("/_email");
    expect(response?.status()).toBe(403);
    await expect(page.getByText("Email Sendbox")).toHaveCount(0);
  });

  test("refuses a request with the wrong secret", { tag: "@vanilla" }, async ({ page }) => {
    const response = await page.goto("/_email?secret=not-the-sendbox-secret");
    expect(response?.status()).toBe(403);
    await expect(page.getByText("Email Sendbox")).toHaveCount(0);
  });

  test("serves the sendbox with the correct secret", { tag: "@vanilla" }, async ({ page }) => {
    const response = await page.goto(`/_email?secret=${encodeURIComponent(sendboxSecret)}`);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { name: "Email Sendbox" })).toBeVisible();
  });
});
