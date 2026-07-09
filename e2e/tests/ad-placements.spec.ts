import { test, expect } from "@playwright/test";
import { logIn } from "./utils-admin";

const PLACEMENTS_URL =
  "/wp-admin/admin.php?page=newspack-ads-display-ads#/placements";

test(
  "Ad placements admin page loads",
  {
    tag: ["@vanilla", "@with-woo"],
  },
  async ({ page }) => {
    /**
     * Newspack Ads placements are managed from the Advertising > Placements
     * admin page. Enabling a placement requires a configured ad unit from a
     * connected Google Ad Manager account, which is beyond the scope of a
     * clean snapshot. This test verifies the page loads and lists the
     * default Global placements.
     */
    await logIn(page);
    await page.goto(PLACEMENTS_URL);

    await expect(
      page.getByText("Global: Above Header").first()
    ).toBeVisible({ timeout: 15000 });

    await expect(
      page.getByText("Global: Below Header").first()
    ).toBeVisible();
  }
);
