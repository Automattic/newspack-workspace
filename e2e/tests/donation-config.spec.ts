import { test, expect } from "@playwright/test";
import { logIn } from "./utils-admin";

const WIZARD_URL = "/wp-admin/admin.php?page=newspack-audience-donations";
const DONATE_PAGE_URL = "/support-our-publication/";

test(
  "Configure donation settings and verify on frontend",
  {
    tag: "@with-woo",
  },
  async ({ page }) => {
    await logIn(page);

    // Target the monthly input by its label. The wizard renders four amount
    // spinbuttons (one-time, monthly, yearly, minimum) and the one-time and
    // monthly ones share the same $15 default, so anything positional or
    // value-based would silently bind to the wrong field.
    const monthlyAmountInput = page.getByLabel(
      "Suggested donation amount per month"
    );

    // The reader-facing Donate block. The donations page is created with no
    // amount attributes of its own, so the block reads the wizard setting on
    // every render -- which is what makes the wizard-to-frontend assertion
    // below meaningful. Key off the frequency wrapper and the input name: the
    // element ids carry a per-render random suffix and are not stable.
    const donateMonthlyAmount = page.locator(
      '.donation-frequency__month input[name="donation_value_month_untiered"]'
    );

    const saveSettings = () =>
      Promise.all([
        page.waitForResponse(
          /\/newspack\/v1\/wizard\/newspack-audience-donations/
        ),
        page.getByRole("button", { name: "Save Settings" }).click(),
      ]);

    // Navigate to the Newspack Donations settings.
    await page.goto(WIZARD_URL);
    await expect(monthlyAmountInput).toHaveValue("15", { timeout: 15000 });

    // Change the monthly amount from $15 to $25 and save.
    await monthlyAmountInput.fill("25");
    await saveSettings();

    // Reload and assert the new amount actually persisted.
    await page.goto(WIZARD_URL);
    await expect(monthlyAmountInput).toHaveValue("25", { timeout: 15000 });

    // Assert the new amount reaches the reader. "Monthly" is the block's
    // default frequency, so its panel is the one on screen without any tab
    // interaction.
    await page.goto(DONATE_PAGE_URL);
    await expect(
      page.getByRole("tab", { name: "Monthly" })
    ).toHaveAttribute("aria-selected", "true");
    await expect(donateMonthlyAmount).toHaveValue("25");

    // Clean up: restore the original amount, and confirm the frontend follows
    // it back. This also proves the assertion above tracks the setting rather
    // than passing against some fixed page state.
    await page.goto(WIZARD_URL);
    await monthlyAmountInput.fill("15");
    await saveSettings();
    await page.goto(WIZARD_URL);
    await expect(monthlyAmountInput).toHaveValue("15", { timeout: 15000 });
    await page.goto(DONATE_PAGE_URL);
    await expect(donateMonthlyAmount).toHaveValue("15");
  }
);
