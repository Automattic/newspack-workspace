import { test, expect } from "@playwright/test";
import { logIn } from "./utils-admin";
import { goToUncached } from "./utils";

const WIZARD_URL = "/wp-admin/admin.php?page=newspack-audience-donations";
const DONATE_PAGE_URL = "/support-our-publication/";

test(
  "Configure donation settings and verify on frontend",
  {
    tag: "@with-woo",
  },
  async ({ page }) => {
    await logIn(page);

    // The monthly suggested amount, targeted by label. The wizard renders four
    // amount spinbuttons per frequency (three tiers plus the untiered
    // "suggested" amount), and the one-time and monthly ones share the same $15
    // default, so anything positional or value-based would bind to the wrong
    // field.
    const monthlyAmountInput = page.getByLabel(
      "Suggested donation amount per month"
    );

    // The reader-facing Donate block. The donations page carries no amount
    // attributes of its own, so the block reflects the wizard setting on every
    // render -- which is what makes the wizard-to-frontend assertion meaningful.
    // Key off the frequency wrapper and input name: the element ids carry a
    // per-render random suffix and are not stable.
    const donateMonthlyAmount = page.locator(
      '.donation-frequency__month input[name="donation_value_month_untiered"]'
    );

    // Change the monthly suggested amount in the wizard and save. Wait for the
    // save request itself, not just any response to the donations endpoint: the
    // wizard also GETs this URL to hydrate, and a hydration refetch racing the
    // click would satisfy a method-agnostic wait and resolve before the write
    // lands. The endpoint registers the read (GET) and the save (POST) as
    // separate routes, so keying on a non-GET response ties the wait to the
    // actual save.
    const saveMonthlyAmount = async (amount: string) => {
      await page.goto(WIZARD_URL);
      await expect(monthlyAmountInput).toBeVisible({ timeout: 15000 });
      await monthlyAmountInput.fill(amount);
      await Promise.all([
        page.waitForResponse(
          (response) =>
            /\/newspack\/v1\/wizard\/newspack-audience-donations/.test(
              response.url()
            ) && response.request().method() !== "GET"
        ),
        page.getByRole("button", { name: "Save Settings" }).click(),
      ]);
    };

    // Assert the reader-facing block reflects a monthly amount. Reload until it
    // does, rather than re-checking a single load: on the WooCommerce platform
    // the amount round-trips through the donation product, and
    // get_donation_settings can briefly serve the just-saved value from a stale
    // object cache -- so the server renders the block with the old amount for a
    // moment after a save, until the cache settles. Re-fetching the page is what
    // surfaces the fresh value; re-checking the already-rendered DOM never would.
    // "Monthly" is the block's default frequency, so its panel is on screen
    // without any tab interaction.
    const expectFrontendMonthly = async (amount: string) => {
      await expect(async () => {
        await goToUncached(page, DONATE_PAGE_URL);
        await expect(
          page.getByRole("tab", { name: "Monthly" })
        ).toHaveAttribute("aria-selected", "true");
        await expect(donateMonthlyAmount).toHaveValue(amount, {
          timeout: 3000,
        });
      }).toPass({ timeout: 30000 });
    };

    // Change the monthly suggested amount to $25 and confirm the reader sees it.
    await saveMonthlyAmount("25");
    await expectFrontendMonthly("25");

    // Restore the $15 default and confirm the block follows it back -- proving
    // the assertion tracks the wizard setting rather than a fixed page state.
    await saveMonthlyAmount("15");
    await expectFrontendMonthly("15");
  }
);
