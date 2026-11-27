import { test, expect } from "@playwright/test";
import {
  clickMyAccountMenuItem,
  fillModalCheckoutBillingDetails,
  fillStripeTestCard,
  getModalCheckout,
  goToMyAccount,
  randomEmailAddress,
} from "./utils";

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
    // The order details table is the checkout's source of truth for what the
    // reader will be charged. Assert on the cart item row, matching just the
    // amount and cadence: the product label has varied across Newspack versions
    // (e.g. "Donate:" then "Donate: Monthly"), but "$15.00 / month" is stable
    // and is the bit worth asserting.
    getModalCheckout(page).locator(
      ".woocommerce-checkout-review-order-table .cart_item"
    )
  ).toContainText(/\$15\.00\s*\/\s*month/);

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
  await expect(page.getByRole("button", { name: "Close" })).not.toBeVisible();

  /**
   * Go to "My Account" page – it's now available as the reader account has been created.
   */
  await goToMyAccount(page);
  await expect(page.locator("#newspack_account_email")).toHaveValue(
    emailAddress
  );
  await clickMyAccountMenuItem(page, "Subscription");
  await expect(page.getByText("Visa card ending in 4242")).toBeVisible();
  await expect(
    page.getByRole("cell", { name: "$15.00 / month" }).first()
  ).toBeVisible();
});
