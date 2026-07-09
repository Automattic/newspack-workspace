import { test, expect } from "@playwright/test";
import { logIn, getEditorCanvas } from "./utils-admin";
import { randomString } from "./utils";

test("Create a sponsor", {
      tag: ['@vanilla', '@with-woo'],
    },
    async ({ page }) => {
  await logIn(page);

  const randomId = randomString(4);
  const sponsorName = `Test Sponsor ${randomId}`;

  /**
   * Navigate to the sponsors list and create a new sponsor.
   */
  await page.goto("/wp-admin/edit.php?post_type=newspack_spnsrs_cpt");
  await page
    .locator("#wpbody-content")
    .getByRole("link", { name: /Add New/ })
    .first()
    .click();
  await page.waitForURL(/post-new\.php\?post_type=newspack_spnsrs_cpt/);

  // Fill in the sponsor name.
  const editor = await getEditorCanvas(page);
  await editor.getByLabel("Add title").fill(sponsorName);

  /**
   * Publish the sponsor.
   */
  await page.getByRole("button", { name: "Publish", exact: true }).click();
  await page
    .getByLabel("Editor publish")
    .getByRole("button", { name: "Publish", exact: true })
    .click();
  await expect(
    page.getByTestId("snackbar").getByText(/Post published|is now live/).first()
  ).toBeVisible({ timeout: 10000 });

  /**
   * Verify the sponsor appears in the sponsors list.
   */
  await page.goto("/wp-admin/edit.php?post_type=newspack_spnsrs_cpt");
  await expect(
    page.getByRole("row").filter({ hasText: sponsorName })
  ).toBeVisible();

  /**
   * Clean up: trash the sponsor.
   */
  const sponsorRow = page.getByRole("row").filter({ hasText: sponsorName });
  await sponsorRow.hover();
  await sponsorRow.getByRole("link", { name: "Trash" }).click();
});
