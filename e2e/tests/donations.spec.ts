import { test, expect } from "@playwright/test";
import { clickMyAccountMenuItem, randomEmailAddress } from "./utils";

const getPageInIframe = (page) =>
  page.frameLocator('iframe[name="newspack_modal_checkout_iframe"]');

const getStripeIframeCard = (page) =>
  getPageInIframe(page).frameLocator(
    // Stripe Elements renders an extra aria-hidden "Secure payment input frame"
    // (the ACH bank-search results frame) alongside the card input frame, so
    // exclude hidden frames to keep this matching a single element.
    `[data-payment-method-type="card"] [title="Secure payment input frame"]:not([aria-hidden="true"])`
  );

const emailAddress = randomEmailAddress();

test("Donations",  {
      tag: '@with-woo',
    },
    async ({page}) => {

  /**
   * Make a donation.
   */
  await page.goto("/support-our-publication/");
  await page.getByRole("button", { name: "Donate Now" }).click();
  await expect(
    // Match just the amount and cadence: the summary's label prefix has varied
    // across Newspack versions (e.g. "Donate:" then "Donate: Monthly:"), but the
    // "$15.00 / month" part is stable and is the bit worth asserting.
    getPageInIframe(page).locator('strong:has-text("$15.00 / month")')
  ).toBeVisible();
  await getPageInIframe(page).getByLabel("Email address *").fill(emailAddress);
  await getPageInIframe(page).getByLabel("First name *").fill("John");
  await getPageInIframe(page).getByLabel("Last name *").fill("Doe");

  await getPageInIframe(page).getByRole("button", { name: "Continue" }).click();

  await getStripeIframeCard(page)
    .getByPlaceholder("1234 1234 1234 1234")
    .fill("4242 4242 4242 42424");
  await getStripeIframeCard(page).getByPlaceholder("MM / YY").fill("04 / 44");
  await getStripeIframeCard(page).getByLabel("Security code").fill("333");

  // Depending on geo, Stripe may want a ZIP code, too.
  const zipLocator = await getStripeIframeCard(page).getByPlaceholder("12345");
  if (await zipLocator.isVisible()) {
    await getStripeIframeCard(page).getByPlaceholder("12345").fill("12345");
  }

  await getPageInIframe(page)
    .getByRole("button", { name: "Donate now" })
    .click();

  await expect(
    page.getByRole("heading", { name: "Transaction successful" })
  ).toBeVisible();

  await expect(page.getByRole("button", { name: "Close" })).toBeVisible();
  await getPageInIframe(page).getByRole("button", { text: "Continue" }).click();
  await expect(page.getByRole("button", { name: "Close" })).not.toBeVisible();

  /**
   * Go to "My Account" page – it's now available as the reader account has been created.
   */
  await page.getByRole("link", { name: "My Account" }).click();
  await page.waitForURL(/my-account/);
  await expect(page.locator("#newspack_account_email")).toHaveValue(
    emailAddress
  );
  await clickMyAccountMenuItem(page, "Subscription");
  await expect(page.getByText("Visa card ending in 4242")).toBeVisible();
  await expect(
    page.getByRole("cell", { name: "$15.00 / month" }).first()
  ).toBeVisible();
});
