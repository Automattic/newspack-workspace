import { test, expect } from "@playwright/test";
import { randomString } from "./utils";
import { logIn, getEditorCanvas } from "./utils-admin";

test("Add Checkout Button block to a page", {
      tag: '@with-woo',
    },
    async ({ page }) => {

  await logIn(page);
  const randomId = randomString(6);
  const pageTitle = `Checkout Test ${randomId}`;

  /**
   * Create a new page with a Checkout Button block.
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

  // Publish the page.
  await page.getByRole("button", { name: "Publish", exact: true }).click();
  await page
    .getByLabel("Editor publish")
    .getByRole("button", { name: "Publish", exact: true })
    .click();
  await expect(
    page.getByTestId("snackbar").getByText("Page published.")
  ).toBeVisible();

  /**
   * Clean up: move the test page to trash via the pages list.
   *
   * Note: a full end-to-end purchase test would require the block to be linked
   * to a configured WooCommerce product, which is beyond the scope of a clean
   * snapshot. This test verifies the block can be inserted and the page persists.
   */
  await page.goto(`/wp-admin/edit.php?post_type=page&s=${encodeURIComponent(pageTitle)}`);
  const row = page.getByRole("row").filter({ hasText: pageTitle }).first();
  await row.hover();
  await row.getByRole("link", { name: "Trash" }).click();
});
