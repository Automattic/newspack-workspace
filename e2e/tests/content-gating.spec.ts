import { test, expect } from "@playwright/test";
import { goToUncached, randomEmailAddress, randomString } from "./utils";
import { logIn, logOut, goToAdminMenu } from "./utils-admin";
import {
  deleteGate,
  getGate,
  getPostUrls,
  saveGateAsActive,
  startNewGate,
} from "./utils-content-gates";

// End-to-end coverage for the Access Control (content gating) system: the
// Audience > Access control wizard plus the reader-facing enforcement it
// configures. Requires the NEWSPACK_CONTENT_GATES flag (set by e2e-setup.sh)
// and -- for front-end enforcement -- WooCommerce Memberships to be inactive,
// since Access Control defers to Memberships whenever that plugin is active
// (e2e-setup.sh deactivates it in the with-woo phase for this reason).
//
// Each test is fully self-contained (creates and deletes its own gate): a
// worker restart -- which Playwright does after any failure, and which
// re-rolls the random name suffix -- must not strand a later test without
// the state an earlier one was supposed to leave behind.

test(
  "Regwall lifecycle: metered registered access from creation to deletion",
  {
    tag: "@vanilla",
  },
  async ({ page }) => {
    // Admin configuration, client-side metering, an in-gate registration
    // round-trip and the teardown add up to well over the default budget.
    test.setTimeout(300000);

    const gateName = `E2E Regwall ${randomString(4)}`;

    /**
     * Create a gate restricting all posts with metered registered access.
     */
    await logIn(page);
    await startNewGate(page);
    await page.getByRole("textbox", { name: "gate name" }).fill(gateName);
    await page.getByRole("checkbox", { name: "Registered access" }).check();
    await page.getByRole("checkbox", { name: "Metering" }).check();
    await page.getByRole("spinbutton", { name: "Free views" }).fill("1");
    // Monthly is the default reset period; assert rather than re-select.
    await expect(
      page.getByRole("radio", { name: "Monthly", exact: true })
    ).toBeChecked();
    await saveGateAsActive(page);

    // The list reflects the saved configuration.
    await expect(
      page.getByRole("heading", { name: new RegExp(`${gateName}\\s+Active`) })
    ).toBeVisible();
    await expect(page.getByText("1 free views per month")).toBeVisible();

    // The gate layouts are editable in the block editor. Verify the link
    // resolves to an editor session before moving to the reader flows.
    await page.getByRole("link", { name: gateName, exact: true }).click();
    await page.waitForURL(/#\/edit\/\d+/);
    await page.getByRole("link", { name: "Edit layout" }).first().click();
    await page.waitForURL(/post\.php.*action=edit/);
    await expect(page.locator("#editor")).toBeAttached();

    /**
     * As an anonymous reader: the first post view is free (metering allows
     * it), the second is locked by the regwall.
     */
    await logOut(page);
    const [firstPostUrl, secondPostUrl] = await getPostUrls(page);

    await goToUncached(page, firstPostUrl);
    await expect(getGate(page)).toBeHidden();
    // Wait for the metering store to record the free view (a localStorage
    // entry keyed metering-<gate_id>) before navigating away, otherwise the
    // next post would count as the first view.
    await page.waitForFunction(() =>
      JSON.stringify(localStorage).includes("metering-")
    );

    await goToUncached(page, secondPostUrl);
    await expect(page.locator("body.newspack-content-locked")).toBeVisible();
    await expect(getGate(page)).toBeVisible();
    // The default registration layout invites the reader to sign up.
    await expect(
      getGate(page).getByText("Continue reading for free")
    ).toBeVisible();

    // The already-read post stays accessible -- metering counts distinct
    // articles, not page loads.
    await goToUncached(page, firstPostUrl);
    await expect(getGate(page)).toBeHidden();

    // Non-post content is not restricted by the all-posts gate.
    await page.goto("/");
    await expect(getGate(page)).toBeHidden();

    /**
     * Registering through the gate's inline form unlocks the content: the
     * reader is signed in and registered access is satisfied.
     */
    await goToUncached(page, secondPostUrl);
    const gate = getGate(page);
    await expect(gate).toBeVisible();
    // Target the real email input by name: a visually-hidden honeypot twin
    // shares the "Email Address" placeholder, so a placeholder lookup is
    // ambiguous.
    await gate.locator('input[name="npe"]').fill(randomEmailAddress());
    await gate.getByRole("button", { name: "Continue" }).click();
    // Registration succeeds and offers email verification; skipping it
    // completes the sign-in, which reloads the post with access granted.
    const verificationModal = page.locator(".newspack__reader-verification");
    await expect(
      verificationModal.getByText("Verify your email")
    ).toBeVisible();
    await verificationModal
      .getByRole("button", { name: "Skip for now" })
      .click();
    await expect(page.locator("body.newspack-content-locked")).toBeHidden({
      timeout: 30000,
    });
    await expect(getGate(page)).toBeHidden();

    /**
     * Delete the gate and verify enforcement stops for anonymous readers.
     */
    await logIn(page);
    await goToAdminMenu("Audience", "Access control", page);
    await deleteGate(page, gateName);

    await logOut(page);
    await goToUncached(page, secondPostUrl);
    await expect(getGate(page)).toBeHidden();
    await expect(page.locator("body.newspack-content-locked")).toBeHidden();
  }
);

test(
  "Paywall lifecycle: paid access unlocked by a subscription purchase",
  {
    tag: "@with-woo",
  },
  async ({ page }) => {
    // Gate creation plus a full Stripe checkout round-trip.
    test.setTimeout(300000);

    const gateName = `E2E Paywall ${randomString(4)}`;

    /**
     * Create a gate restricting all posts with paid access tied to an active
     * subscription on the site's subscription products (the Newspack
     * donation products are Woo subscriptions).
     */
    await logIn(page);
    await startNewGate(page);
    await page.getByRole("textbox", { name: "gate name" }).fill(gateName);
    await page.getByRole("checkbox", { name: "Paid access" }).check();
    await page.getByRole("checkbox", { name: "Active subscription" }).check();
    const productPicker = page.getByRole("combobox").last();
    await productPicker.click();
    // The donate flow below purchases the monthly donation, so gate on that
    // exact product -- any other selection would never unlock.
    await page.getByRole("option", { name: /Donate: Monthly/ }).click();
    await saveGateAsActive(page);
    await expect(
      page.getByRole("heading", { name: new RegExp(`${gateName}\\s+Active`) })
    ).toBeVisible();

    /**
     * An anonymous reader hits the paywall immediately: paid access with no
     * metering leaves no free views.
     */
    await logOut(page);
    const [postUrl] = await getPostUrls(page);
    await goToUncached(page, postUrl);
    await expect(getGate(page)).toBeVisible();

    /**
     * Buy a monthly donation (a Woo subscription product): checkout signs the
     * reader in and activates a subscription, satisfying the access rule.
     */
    const emailAddress = randomEmailAddress();
    await page.goto("/support-our-publication/");
    await page.getByRole("button", { name: "Donate Now" }).click();
    const modalCheckout = page.frameLocator(
      'iframe[name="newspack_modal_checkout_iframe"]'
    );
    await modalCheckout.getByLabel("Email address *").fill(emailAddress);
    await modalCheckout.getByLabel("First name *").fill("John");
    await modalCheckout.getByLabel("Last name *").fill("Doe");
    await modalCheckout.getByRole("button", { name: "Continue" }).click();
    const cardFields = modalCheckout.frameLocator(
      '[data-payment-method-type="card"] [title="Secure payment input frame"]:not([aria-hidden="true"])'
    );
    await cardFields
      .getByPlaceholder("1234 1234 1234 1234")
      .fill("4242 4242 4242 4242");
    await cardFields.getByPlaceholder("MM / YY").fill("04 / 44");
    await cardFields.getByLabel("Security code").fill("333");
    const zipCode = cardFields.getByPlaceholder("12345");
    if (await zipCode.isVisible()) {
      await zipCode.fill("12345");
    }
    await modalCheckout.getByRole("button", { name: "Donate now" }).click();
    await expect(
      page.getByRole("heading", { name: "Transaction successful" })
    ).toBeVisible({ timeout: 30000 });
    await modalCheckout.getByRole("button", { name: "Continue" }).click();

    /**
     * The subscriber now passes the paywall.
     */
    await goToUncached(page, postUrl);
    await expect(getGate(page)).toBeHidden();
    await expect(page.locator("body.newspack-content-locked")).toBeHidden();

    /**
     * Clean up: remove the gate so later specs see an ungated site.
     */
    await logIn(page);
    await goToAdminMenu("Audience", "Access control", page);
    await deleteGate(page, gateName);
  }
);
