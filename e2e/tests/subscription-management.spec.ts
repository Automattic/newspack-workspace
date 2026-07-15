import { test, expect } from "@playwright/test";
import { randomEmailAddress } from "./utils";

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

test(
  "Manage subscription after donation",
  {
    tag: "@with-woo",
  },
  async ({ page }) => {
    /**
     * Make a donation.
     */
    await page.goto("/support-our-publication/");
    await page.getByRole("button", { name: "Donate Now" }).click();
    await expect(
      getPageInIframe(page).locator(
        'strong:has-text("Donate: $15.00 / month")'
      )
    ).toBeVisible();
    await getPageInIframe(page)
      .getByLabel("Email address *")
      .fill(emailAddress);
    await getPageInIframe(page).getByLabel("First name *").fill("John");
    await getPageInIframe(page).getByLabel("Last name *").fill("Doe");

    await getPageInIframe(page)
      .getByRole("button", { name: "Continue" })
      .click();

    await getStripeIframeCard(page)
      .getByPlaceholder("1234 1234 1234 1234")
      .fill("4242 4242 4242 42424");
    await getStripeIframeCard(page)
      .getByPlaceholder("MM / YY")
      .fill("04 / 44");
    await getStripeIframeCard(page).getByLabel("Security code").fill("333");

    // Depending on geo, Stripe may want a ZIP code, too.
    const zipLocator = await getStripeIframeCard(page).getByPlaceholder(
      "12345"
    );
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
    await getPageInIframe(page)
      .getByRole("button", { text: "Continue" })
      .click();
    await expect(
      page.getByRole("button", { name: "Close" })
    ).not.toBeVisible();

    /**
     * Navigate directly to the subscriptions list in My Account.
     */
    await page.goto("/my-account/subscriptions/");
    await expect(page.getByText("Visa card ending in 4242")).toBeVisible();

    /**
     * Open the individual subscription page via its href.
     */
    const viewSubscriptionLink = page.locator('a[href*="view-subscription"]').first();
    const subscriptionHref = await viewSubscriptionLink.getAttribute("href");
    expect(subscriptionHref).toBeTruthy();
    await page.goto(subscriptionHref);
    await expect(page).toHaveURL(/view-subscription/);

    /**
     * Cancel the subscription by navigating directly to its cancel URL.
     * The cancel link may render outside the viewport on smaller screens.
     */
    const cancelHref = await page
      .getByRole("link", { name: /Cancel/ })
      .first()
      .getAttribute("href");
    expect(cancelHref).toBeTruthy();
    await page.goto(cancelHref);

    // Confirm cancellation if a confirmation step is presented.
    const confirmButton = page.getByRole("button", {
      name: /Cancel Subscription/,
    });
    if (await confirmButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await confirmButton.click();
    }

    /**
     * Verify the subscription status reflects the cancellation.
     */
    await expect(
      page.getByText(/Cancelled|Pending Cancellation/i).first()
    ).toBeVisible();
  }
);
