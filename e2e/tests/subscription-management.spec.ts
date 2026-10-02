import { test, expect } from "@playwright/test";
import {
  fillModalCheckoutBillingDetails,
  fillStripeTestCard,
  getModalCheckout,
  randomEmailAddress,
} from "./utils";

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
    // Match on the amount + interval only. The line's product-name prefix varies
    // ("Donate: ..." vs "Donate: Monthly ..."), but "$15.00 / month" is stable
    // across those variants and is what actually confirms the right donation.
    await expect(
      getModalCheckout(page).locator('strong:has-text("$15.00 / month")')
    ).toBeVisible();

    await fillModalCheckoutBillingDetails(page, emailAddress);
    await fillStripeTestCard(page);

    await getModalCheckout(page)
      .getByRole("button", { name: "Donate now" })
      .click();

    await expect(
      page.getByRole("heading", { name: "Transaction successful" })
    ).toBeVisible();

    await expect(page.getByRole("button", { name: "Close" })).toBeVisible();
    await getModalCheckout(page)
      .getByRole("button", { name: "Continue" })
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
    const viewSubscriptionLink = page
      .locator('a[href*="view-subscription"]')
      .first();
    await expect(viewSubscriptionLink).toHaveAttribute("href", /.+/);
    const subscriptionHref = await viewSubscriptionLink.getAttribute("href");
    await page.goto(subscriptionHref);
    await expect(page).toHaveURL(/view-subscription/);

    /**
     * Cancel the subscription by navigating directly to its cancel URL.
     * The cancel link may render outside the viewport on smaller screens.
     */
    const cancelLink = page.getByRole("link", { name: /Cancel/ }).first();
    await expect(cancelLink).toHaveAttribute("href", /.+/);
    const cancelHref = await cancelLink.getAttribute("href");
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
