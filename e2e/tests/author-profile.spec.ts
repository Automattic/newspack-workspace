import { test, expect } from "@playwright/test";
import { logIn, logOut } from "./utils-admin";
import { goToUncached, randomString } from "./utils";

test(
  "Author bio renders for signed-out readers",
  {
    tag: ["@vanilla", "@with-woo"],
  },
  async ({ page }) => {
    // Keep the bio well under the theme's 200-character truncation threshold
    // (Customizer setting `author_bio_length`), so the single-post copy is the
    // verbatim text rather than a cut-off-at-a-word-boundary prefix.
    const bio = `Bio marker ${randomString(6)}. Reporting on local civic matters.`;

    // Set the biographical info and save. Activate the button from the keyboard
    // rather than clicking it: the profile screen overflows horizontally on a
    // phone viewport, which scales the page and skews the coordinates a click
    // hit-tests against, so a plain click never lands on mobile.
    const saveProfile = async (biographicalInfo: string) => {
      await page.goto("/wp-admin/profile.php");
      await page.locator("#description").fill(biographicalInfo);
      await page.locator("#submit").focus();
      await page.keyboard.press("Enter");
      await expect(page.locator("#message")).toContainText("Profile updated");
    };

    /**
     * Set the author's biographical info in the admin.
     */
    await logIn(page);
    await saveProfile(bio);

    /**
     * The bio is reader-facing, so verify it signed out -- as a reader would
     * see it, and without the admin bar or any editor-only markup in the way.
     */
    await logOut(page);

    // Author archive: the bio is rendered as the archive description. Assert on
    // the bio rather than the author's name -- the display name is not what this
    // test is about, and checkout flows elsewhere in the suite can rewrite it.
    await goToUncached(page, "/author/admin/");
    await expect(page.locator(".page-title")).toContainText(/Author Archives/i);
    await expect(page.locator(".page-header .taxonomy-description")).toHaveText(
      bio
    );

    // Single post: the bio renders below the article. Follow a post from the
    // archive rather than hardcoding one -- every entry listed here is by this
    // author, so any of them exercises the same template path. Skip sponsored
    // entries: a sponsor's byline replaces the author's, which suppresses the
    // author bio by design.
    const firstPostUrl = await page
      .locator("article:not(:has(.sponsor-label)) .entry-title a")
      .first()
      .getAttribute("href");
    await goToUncached(page, firstPostUrl);
    await expect(page.locator(".author-bio")).toContainText(bio);
    await expect(
      page.locator(".author-bio").getByRole("link", { name: /^More by / })
    ).toBeVisible();

    /**
     * Clean up: clear the bio, and confirm that removing it removes the
     * reader-facing block. This also proves the assertions above track the
     * profile field rather than passing against some fixed page state.
     */
    await logIn(page);
    await saveProfile("");

    await logOut(page);
    await goToUncached(page, firstPostUrl);
    await expect(page.locator(".author-bio")).toHaveCount(0);
  }
);
