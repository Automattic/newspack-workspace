import { test, expect } from "@playwright/test";

import { logIn, getEditorCanvas } from "./utils-admin";

test("Top featured post and edit homepage", {
        tag: ['@vanilla', '@with-woo'],
    },
    async ({ page }) => {
    await logIn(page);

    // Go to the homepage and open the first featured post via its title link.
    await page.goto('/');
    await page
      .locator('.wp-block-newspack-blocks-homepage-articles .entry-title a')
      .first()
      .click();
    await page.waitForURL(url => url.pathname !== '/');

    // Grab the post title from the post page.
    const featuredPostTitle = (
      await page.locator('h1.entry-title').first().textContent()
    )?.trim() ?? '';
    expect(featuredPostTitle).not.toBe('');

    // Back to the homepage, then open the page editor via the admin bar.
    await page.goto('/');
    await page.locator('#wp-admin-bar-edit a').click();

    const editor = await getEditorCanvas(page);
    await expect(
      editor
        .locator('.wp-block-newspack-blocks-homepage-articles')
        .first()
        .filter({ hasText: featuredPostTitle })
    ).toBeVisible();
});
