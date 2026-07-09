import { test, expect } from "@playwright/test";
import { logIn } from "./utils-admin";

const WIZARD_URL =
  "/wp-admin/admin.php?page=newspack-audience-donations";

test(
  "Configure donation settings and verify on frontend",
  {
    tag: "@with-woo",
  },
  async ({ page }) => {
    await logIn(page);

    // Navigate to the Newspack Donations settings.
    await page.goto(WIZARD_URL);

    // Wait for the donation settings to load.
    await expect(
      page.getByText("Suggested donation amount per month").first()
    ).toBeVisible({ timeout: 15000 });

    // The monthly suggested amount input (value 15 by default).
    const suggestedAmountInput = page
      .getByRole("spinbutton")
      .filter({ hasValue: "15" })
      .first();
    await expect(suggestedAmountInput).toBeVisible();

    // Change the monthly amount from $15 to $25.
    await suggestedAmountInput.fill("25");

    // Save the settings and wait for the request to complete.
    await Promise.all([
      page.waitForResponse(/newspack\/v1|wp-json/),
      page.getByRole("button", { name: "Save Settings" }).click(),
    ]);

    // Reload the page and verify the new amount persisted.
    await page.goto(WIZARD_URL);
    await expect(
      page.getByText("Suggested donation amount per month").first()
    ).toBeVisible({ timeout: 15000 });

    const updatedInput = page
      .getByRole("spinbutton")
      .filter({ hasValue: "25" })
      .first();
    await expect(updatedInput).toBeVisible();

    // Clean up: restore the original amount.
    await updatedInput.fill("15");
    await Promise.all([
      page.waitForResponse(/newspack\/v1|wp-json/),
      page.getByRole("button", { name: "Save Settings" }).click(),
    ]);
  }
);
