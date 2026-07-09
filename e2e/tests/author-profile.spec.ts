import { test, expect } from "@playwright/test";
import { logIn } from "./utils-admin";

test("Author bio and archive", {
		tag: ['@vanilla', '@with-woo'],
	},
	async ({page}) => {
	await logIn(page);

	// Navigate directly to the author archive for the admin user.
	await page.goto('/author/admin/');
	await expect(page).toHaveURL(/\/author\//);

	// Verify the author archive page shows posts.
	const posts = page.locator('article');
	await expect(posts.first()).toBeVisible();

	// Verify the archive page has a title or heading.
	await expect(
		page.locator('.page-title, h1, h2').first()
	).toBeVisible();

	// Click on a post title from the archive.
	const postLink = posts.first().locator('.entry-title a, h2 a').first();
	await postLink.click();

	// Verify navigation to the post page.
	await expect(page.locator('h1').first()).toBeVisible();
	await expect(page.locator('.entry-content, .post-content').first()).toBeVisible();
});
