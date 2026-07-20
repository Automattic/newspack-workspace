import { test, expect } from "@playwright/test";
import { randomString } from "./utils";
import { isMobileAdmin, logIn } from "./utils-admin";
import {
  clickAddNewGate,
  deleteGate,
  saveGateAsActive,
} from "./utils-content-gates";

// Admin coverage for Premium Newsletters (Newsletters > Premium), which reuses
// the Access Control gate machinery for newsletter lists. Full reader-facing
// coverage needs a connected ESP with lists, so this spec exercises the admin
// flows only: creating a premium newsletter gate against all lists with a paid
// access rule that works without WooCommerce, then deleting it.

test(
  "Create and delete a premium newsletter gate",
  {
    tag: "@vanilla",
  },
  async ({ page }) => {
    const newsletterGateName = `E2E Premium NL ${randomString(4)}`;

    await logIn(page);

    /**
     * The wizard hangs off the Newsletters CPT menu as "Premium". Assert the
     * menu placement, then navigate directly: goToAdminMenu can't be used
     * here, as "Newsletters" also matches the "All Newsletters" submenu entry
     * (strict mode violation), and on a phone the whole menu sits behind the
     * "Menu" toggle.
     */
    await page.goto("/wp-admin/edit.php?post_type=newspack_nl_cpt");
    if (await isMobileAdmin(page)) {
      await page.getByRole("menuitem", { name: "Menu" }).click();
    }
    await expect(
      page
        .getByLabel("Main menu", { exact: true })
        .getByRole("link", { name: "Premium", exact: true })
    ).toBeVisible();
    await page.goto("/wp-admin/admin.php?page=newspack-premium-newsletters");

    /**
     * Start a premium newsletter restricting all lists. Without an ESP
     * connected only the "all lists" rule is available, which is exactly the
     * no-ESP admin flow a publisher would see.
     */
    await clickAddNewGate(page, "Add new premium newsletter");
    await page.waitForURL(/#\/edit\/new/);

    await page.getByRole("textbox", { name: /name/ }).fill(newsletterGateName);

    /**
     * Gate the lists behind a whitelisted email domain -- a paid access rule
     * that needs no WooCommerce products, so it works in the vanilla phase.
     */
    await page.getByRole("checkbox", { name: "Paid access" }).check();
    await page
      .getByRole("checkbox", { name: "Whitelisted email domain" })
      .check();
    await page
      .getByRole("textbox", { name: "Whitelisted email domain" })
      .fill("example.com");

    await saveGateAsActive(page);
    await expect(
      page.getByRole("heading", {
        name: new RegExp(`${newsletterGateName}\\s+Active`),
      })
    ).toBeVisible();

    /**
     * Delete it again from the edit screen's More menu.
     */
    await deleteGate(page, newsletterGateName);
  }
);
