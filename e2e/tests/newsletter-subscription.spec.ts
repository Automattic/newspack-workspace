import { test, expect } from "@playwright/test";
import { randomString } from "./utils";
import { logIn, getEditorCanvas } from "./utils-admin";

test(
  "Add Newsletter Subscription block to a page",
  {
    tag: ["@vanilla", "@with-woo"],
  },
  async ({ page }) => {
    await logIn(page);

    const randomId = randomString(4);
    const pageTitle = `Newsletter Test ${randomId}`;

    /**
     * Create a new page with a Newsletter Subscription block.
     */
    await page.goto("/wp-admin/post-new.php?post_type=page");
    const editor = await getEditorCanvas(page);
    await editor.getByLabel("Add title").fill(pageTitle);

    // Use slash command to insert the block inside the editor canvas. The block
    // is titled "Newsletter Subscription Form" with no keywords, so the inserter
    // matches on the title -- search "subscription" ("subscribe" does not match
    // the "Subscription" title token).
    await editor.getByLabel("Add default block").click();
    await page.keyboard.type("/subscription");
    await page
      .getByRole("option", { name: /Newsletter Subscription/i })
      .first()
      .click();

    // Verify the block renders in the editor. Re-acquire the canvas scope: the
    // editor-canvas iframe can remount right after a block insert, which
    // invalidates a frame locator captured earlier.
    const editorAfterInsert = await getEditorCanvas(page);
    await expect(
      editorAfterInsert.locator(".wp-block-newspack-newsletters-subscribe")
    ).toBeVisible({ timeout: 10000 });

    // Publish the page.
    await page.getByRole("button", { name: "Publish", exact: true }).click();
    await page
      .getByLabel("Editor publish")
      .getByRole("button", { name: "Publish", exact: true })
      .click();
    await expect(
      page.getByTestId("snackbar").getByText("Page published.")
    ).toBeVisible({ timeout: 10000 });

    /**
     * Clean up: move the test page to trash via the pages list.
     *
     * Note: verifying the frontend subscription flow requires an ESP
     * (Mailchimp, ActiveCampaign, etc.) to be connected, which is beyond
     * the scope of a clean snapshot. This test verifies the block can be
     * inserted and the page persists.
     */
    await page.goto(`/wp-admin/edit.php?post_type=page&s=${encodeURIComponent(pageTitle)}`);
    const row = page.getByRole("row").filter({ hasText: pageTitle }).first();
    await row.hover();
    await row.getByRole("link", { name: "Trash" }).click();
  }
);
