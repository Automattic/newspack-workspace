import { test, expect } from "@playwright/test";
import {
  clickMyAccountMenuItem,
  fillModalCheckoutBillingDetails,
  fillStripeTestCard,
  getModalCheckout,
  goToMyAccount,
  goToUncached,
  randomEmailAddress,
  randomString,
} from "./utils";
import {
  logIn,
  logOut,
  getEditorCanvas,
  openEditorSettingsSidebar,
} from "./utils-admin";

const PRODUCT_PRICE = "25";

test("Add Checkout Button block to a page and buy the product", {
      tag: '@with-woo',
    },
    async ({ page }) => {

  // The whole flow -- create a product, build a page, then buy through Stripe --
  // is well past what the default 120s allows.
  test.setTimeout(240000);

  const randomId = randomString(6);
  const productName = `E2E Product ${randomId}`;
  const pageTitle = `Checkout Test ${randomId}`;
  const buttonText = "Buy now";
  const emailAddress = randomEmailAddress();

  await logIn(page);

  /**
   * Create something to sell. The site's only other products are the donation
   * ones, which the checkout treats as donations -- pointing at those would
   * re-test the donate flow rather than this block.
   */
  await page.goto("/wp-admin/post-new.php?post_type=product");
  await page.locator("#title").fill(productName);
  await page.locator("#_regular_price").fill(PRODUCT_PRICE);
  await page.locator("#publish").click();
  await expect(page.locator("#_regular_price")).toHaveValue(PRODUCT_PRICE, {
    timeout: 15000,
  });

  /**
   * Create a page with a Checkout Button block pointing at that product.
   */
  await page.goto("/wp-admin/post-new.php?post_type=page");
  const editor = await getEditorCanvas(page);
  await editor.getByLabel("Add title").fill(pageTitle);

  // Open the top-bar block inserter and add a Checkout Button block.
  await page.getByLabel("Block Inserter").click();
  await page.getByPlaceholder("Search").fill("Checkout Button");
  await page.getByRole("option", { name: "Checkout Button" }).first().click();
  // Close the inserter panel.
  await page.keyboard.press("Escape");

  // Verify the block was inserted in the editor.
  await expect(
    editor.locator('[data-type="newspack-blocks/checkout-button"]')
  ).toBeVisible({ timeout: 10000 });

  // The block renders nothing at all without both a product and button text.
  await editor.getByLabel("Button text").fill(buttonText);

  // The product picker lives in the block's sidebar controls, which are behind
  // the Settings sidebar -- closed by default on a phone viewport.
  await openEditorSettingsSidebar(page);
  await page.getByLabel("Product").fill(productName);
  await page
    .getByRole("option", { name: new RegExp(`\\d+: ${productName}`) })
    .click();

  // Publish the page.
  await page.getByRole("button", { name: "Publish", exact: true }).click();
  await page
    .getByLabel("Editor publish")
    .getByRole("button", { name: "Publish", exact: true })
    .click();
  await expect(
    page.getByTestId("snackbar").getByText("Page published.")
  ).toBeVisible();

  // Take the permalink from the publish snackbar. Scope to it: an unscoped
  // "View Page" role lookup also matches admin chrome links that resolve to the
  // same accessible name but point at the pages list.
  const pageUrl = await page
    .getByTestId("snackbar")
    .getByRole("link", { name: "View Page" })
    .getAttribute("href");

  /**
   * Buy the product the way a reader would: signed out, from the front end.
   */
  await logOut(page);
  await goToUncached(page, pageUrl);
  await page.getByRole("button", { name: buttonText }).click();

  const modalCheckout = getModalCheckout(page);
  await expect(
    modalCheckout.locator(
      `strong:has-text("${productName}: $${PRODUCT_PRICE}.00")`
    )
  ).toBeVisible();

  await fillModalCheckoutBillingDetails(page, emailAddress);
  await fillStripeTestCard(page);
  await modalCheckout
    .getByRole("button", { name: /Complete transaction/ })
    .click();

  await expect(
    page.getByRole("heading", { name: "Transaction successful" })
  ).toBeVisible({ timeout: 30000 });

  /**
   * Checking out creates and signs in a reader account, so the purchase should
   * now be on their orders list -- the reader-visible proof that the sale went
   * through, rather than just a success message.
   */
  await goToMyAccount(page);
  await expect(page.locator("#newspack_account_email")).toHaveValue(
    emailAddress
  );
  await clickMyAccountMenuItem(page, "Orders");
  await expect(
    page.getByRole("row").filter({ hasText: `$${PRODUCT_PRICE}.00` }).first()
  ).toBeVisible();

  /**
   * Clean up: trash the test page and the product.
   */
  await logIn(page);
  const listings: [string, string][] = [
    ["/wp-admin/edit.php?post_type=page", pageTitle],
    ["/wp-admin/edit.php?post_type=product", productName],
  ];
  for (const [listUrl, title] of listings) {
    await page.goto(`${listUrl}&s=${encodeURIComponent(title)}`);
    const row = page.getByRole("row").filter({ hasText: title }).first();
    await row.hover();
    await row.getByRole("link", { name: "Trash" }).click();
    // Wait for the trash to actually land. Without this the test can end while
    // the click's navigation is still in flight, which cancels it -- leaving the
    // item behind for every later run to trip over.
    await expect(page.locator("#message")).toContainText("moved to the Trash");
  }
});
