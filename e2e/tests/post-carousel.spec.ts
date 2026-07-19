import { test, expect } from "@playwright/test";
import { logIn, getEditorCanvas } from "./utils-admin";
import { randomString } from "./utils";

test("Post Carousel block renders and navigates", {
        tag: ['@vanilla', '@with-woo'],
    },
    async ({ page }) => {

    await logIn(page);
    const randomId = randomString(4);
    const pageTitle = `Carousel Test ${randomId}`;

    /**
     * Create a new page with a Post Carousel block.
     */
    await page.goto("/wp-admin/post-new.php?post_type=page");
    const editor = await getEditorCanvas(page);
    await editor.getByLabel("Add title").fill(pageTitle);

    // Open the block inserter and add a Content Carousel block.
    await editor.getByLabel("Add default block").click();
    await page.keyboard.type("/post-carousel");
    await page.getByRole("option", { name: /Content Carousel|Post Carousel/ }).click();

    // Wait for the carousel block to render with posts in the editor.
    await expect(
        editor.locator('.wp-block-newspack-blocks-carousel .swiper-slide')
    ).toHaveCount(3, { timeout: 10000 });

    // Publish the page.
    await page.getByRole("button", { name: "Publish", exact: true }).click();
    await page
        .getByLabel("Editor publish")
        .getByRole("button", { name: "Publish", exact: true })
        .click();
    await expect(
        page.getByTestId("snackbar").getByText("Page published.")
    ).toBeVisible();

    // Get the published page URL from the snackbar.
    const pageURL = await page
        .getByTestId("snackbar")
        .getByRole("link", { name: /view page/i })
        .getAttribute("href");
    expect(pageURL).toBeTruthy();
    await page.goto(pageURL);

    /**
     * Verify the carousel is visible on the frontend.
     */
    const carousel = page.locator('.wp-block-newspack-blocks-carousel');
    await expect(carousel).toBeVisible();

    // Bring the carousel into view before exercising it. Playwright auto-scrolls
    // to an element only at the moment it interacts with it, so without this the
    // arrow click and slide transition happen against a carousel still below the
    // fold -- the assertions pass, but the recording never shows the navigation.
    // Scroll it up first so the tape actually captures the interaction.
    await carousel.scrollIntoViewIfNeeded();

    // Verify at least one post title is visible within the carousel.
    const carouselArticles = carousel.locator('article');
    await expect(carouselArticles.first()).toBeVisible();
    const firstPostTitle = await carousel.locator('.entry-title a').first().textContent();
    expect(firstPostTitle.trim().length).toBeGreaterThan(0);

    /**
     * Advance the carousel. On desktop it exposes prev/next arrows; on a mobile
     * viewport those are hidden (navigation is via swipe + pagination dots), so
     * only exercise the arrow when it's actually visible.
     */
    const activeSlideTitle = carousel
        .locator('.swiper-slide-active .entry-title a')
        .first();
    await expect(activeSlideTitle).toBeVisible();

    const nextButton = carousel.locator('.swiper-button-next');
    if (await nextButton.isVisible()) {
      // Capture the current active-slide title, advance, and assert the active
      // slide actually changed -- waiting on the title (rather than a fixed
      // timeout) makes the transition check deterministic.
      const initialActiveTitle = (await activeSlideTitle.textContent()).trim();
      await nextButton.click();
      await expect(activeSlideTitle).not.toHaveText(initialActiveTitle);
    }

    /**
     * Click a post title link within the carousel to navigate to it.
     */
    const postLink = carousel.locator('.swiper-slide-active .entry-title a').first();
    const expectedPostTitle = await postLink.textContent();
    await postLink.click();

    // Verify navigation to the post page.
    await page.waitForURL(/\/\d{4}\/\d{2}\/|\/archives\/|\?p=/);
    await expect(page.locator('h1.entry-title')).toBeVisible();
    const postPageTitle = await page.locator('h1.entry-title').textContent();
    expect(postPageTitle.trim()).toBe(expectedPostTitle.trim());

    /**
     * Clean up: move the test page to trash via the pages list.
     */
    await page.goto(`/wp-admin/edit.php?post_type=page&s=${encodeURIComponent(pageTitle)}`);
    const row = page.getByRole("row").filter({ hasText: pageTitle }).first();
    await row.hover();
    await row.getByRole("link", { name: "Trash" }).click();
});
