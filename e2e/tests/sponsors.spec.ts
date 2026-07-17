import { test, expect } from "@playwright/test";
import {
  logIn,
  logOut,
  getEditorCanvas,
  openEditorSettingsPanel,
} from "./utils-admin";
import { goToUncached, randomString } from "./utils";

test(
  "Create a sponsor and label sponsored content",
  {
    tag: ["@vanilla", "@with-woo"],
  },
  async ({ page }) => {
    await logIn(page);

    const randomId = randomString(4);
    const sponsorName = `Test Sponsor ${randomId}`;
    const postTitle = `Sponsored Post ${randomId}`;

    const publish = async (publishedNotice: RegExp) => {
      await page.getByRole("button", { name: "Publish", exact: true }).click();
      await page
        .getByLabel("Editor publish")
        .getByRole("button", { name: "Publish", exact: true })
        .click();
      await expect(
        page.getByTestId("snackbar").getByText(publishedNotice).first()
      ).toBeVisible({ timeout: 10000 });
    };

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

    const sponsorEditor = await getEditorCanvas(page);
    await sponsorEditor.getByLabel("Add title").fill(sponsorName);
    await publish(/Post published|is now live/);

    /**
     * Verify the sponsor appears in the sponsors list.
     */
    await page.goto("/wp-admin/edit.php?post_type=newspack_spnsrs_cpt");
    await expect(
      page.getByRole("row").filter({ hasText: sponsorName })
    ).toBeVisible();

    /**
     * Publishing a sponsor creates a matching term in the sponsors taxonomy,
     * which is how a sponsor gets attached to content. Write a post and assign
     * the sponsor to it.
     */
    await page.goto("/wp-admin/post-new.php");
    const postEditor = await getEditorCanvas(page);
    await postEditor.getByLabel("Add title").fill(postTitle);

    const sponsorsPanel = await openEditorSettingsPanel(page, "Sponsors");
    await expect(sponsorsPanel).toContainText("Select one or more sponsors:");
    await sponsorsPanel.getByRole("checkbox", { name: sponsorName }).check();
    await publish(/Post published/);

    // Take the permalink from the publish snackbar. Scope to it: an unscoped
    // "View Post" role lookup also matches admin chrome links that resolve to
    // the same accessible name but point at the posts list.
    const postUrl = await page
      .getByTestId("snackbar")
      .getByRole("link", { name: "View Post" })
      .getAttribute("href");

    /**
     * Verify the sponsorship is disclosed to readers. Check this signed out:
     * the labelling exists to tell readers the content is paid for, so the
     * signed-out view is the one that matters.
     */
    await logOut(page);
    await goToUncached(page, postUrl);

    // The "Sponsored" flag, and the sponsor's byline in place of the author's.
    await expect(page.locator(".sponsor-label .flag")).toHaveText("Sponsored");
    await expect(page.locator(".byline.sponsor-byline")).toContainText(
      `Sponsored by ${sponsorName}`
    );
    await expect(page.locator(".byline:not(.sponsor-byline)")).toHaveCount(0);

    // On a single post the disclaimer sits behind a toggle.
    await expect(page.locator("#sponsor-info")).toHaveAttribute(
      "aria-expanded",
      "false"
    );
    await page.locator("#sponsor-info-toggle").click();
    await expect(page.locator(".sponsor-label")).toHaveClass(/show-info/);
    await expect(page.locator("#sponsor-info")).toContainText(
      `This content was commissioned and paid for by ${sponsorName}`
    );

    /**
     * Clean up: trash the post and the sponsor.
     */
    await logIn(page);
    const listings: [string, string][] = [
      ["/wp-admin/edit.php", postTitle],
      ["/wp-admin/edit.php?post_type=newspack_spnsrs_cpt", sponsorName],
    ];
    for (const [listUrl, title] of listings) {
      const separator = listUrl.includes("?") ? "&" : "?";
      await page.goto(`${listUrl}${separator}s=${encodeURIComponent(title)}`);
      const row = page.getByRole("row").filter({ hasText: title }).first();
      await row.hover();
      await row.getByRole("link", { name: "Trash" }).click();
      // Wait for the trash to actually land. Without this the test can end while
      // the click's navigation is still in flight, which cancels it -- leaving a
      // published sponsored post behind, which suppresses the author bio and so
      // breaks the author spec on the next run.
      await expect(page.locator("#message")).toContainText("moved to the Trash");
    }
  }
);
