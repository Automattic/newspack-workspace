import { test, expect } from "@playwright/test";
import { logIn } from "./utils-admin";
import { goToUncached } from "./utils";

const PROVIDERS_URL = "/wp-admin/admin.php?page=newspack-ads-display-ads#/";
const PLACEMENTS_URL =
  "/wp-admin/admin.php?page=newspack-ads-display-ads#/placements";

// The ad unit to attach to the placement. Newspack Ads ships these defaults, so
// no Google Ad Manager account is needed to have something to place.
const AD_UNIT_CODE = "newspack_in_article_1";
const AD_UNIT_OPTION = `Newspack In-Article 1 (${AD_UNIT_CODE})`;

test(
  "Ad placement renders an ad slot once enabled",
  {
    tag: ["@vanilla", "@with-woo"],
  },
  async ({ page }) => {
    await logIn(page);

    // The provider toggles carry no accessible name, so target them through the
    // card that names the provider rather than by role/label.
    const gamToggle = page
      .locator(".newspack-action-card")
      .filter({ hasText: "Google Ad Manager" })
      .first()
      .locator(".components-form-toggle__input");

    // Saving a provider toggle re-renders the card and swaps the input for a
    // fresh node, so check()/uncheck() report "did not change its state" -- they
    // verify against the element they clicked, which is detached by then. Click
    // only when the toggle isn't already where we want it, and assert the
    // outcome from the UI instead.
    const setGamProvider = async (enabled: boolean) => {
      await gamToggle.waitFor({ timeout: 15000 });
      if ((await gamToggle.isChecked()) !== enabled) {
        await gamToggle.click();
      }
    };

    const placementCard = (name: string) =>
      page.locator(".newspack-wizard-ads-placement").filter({ hasText: name });
    const aboveHeaderCard = placementCard("Global: Above Header");

    // The rendered slot on the front end. Without a live Google Ad Manager
    // account no creative fills it, but the slot itself is what the placement
    // setting controls, and it carries the ad unit the wizard assigned.
    const aboveHeaderSlot = page.locator(
      ".newspack_global_ad.global_above_header"
    );

    /**
     * Enable the Google Ad Manager provider. A placement only outputs anything
     * when its provider is active, so this is a precondition of the placement
     * work below rather than a separate concern.
     */
    await page.goto(PROVIDERS_URL);
    await setGamProvider(true);
    await expect(page.getByText("No credentials provided.").first()).toBeVisible(
      { timeout: 15000 }
    );

    /**
     * The placements screen lists the slots a publisher can fill.
     */
    await page.goto(PLACEMENTS_URL);
    await expect(aboveHeaderCard).toContainText("Global: Above Header", {
      timeout: 15000,
    });
    await expect(placementCard("Global: Below Header")).toBeVisible();

    // Nothing is rendered for a placement that is off.
    await goToUncached(page, "/");
    await expect(aboveHeaderSlot).toHaveCount(0);

    /**
     * Enable the "Global: Above Header" placement and attach an ad unit. Both
     * are required: a placement with no ad unit renders nothing at all.
     */
    await page.goto(PLACEMENTS_URL);
    await aboveHeaderCard.getByRole("button", { name: "Enable" }).click();
    await aboveHeaderCard
      .locator("select")
      .selectOption({ label: AD_UNIT_OPTION });
    await aboveHeaderCard
      .getByRole("button", { name: "Enable", exact: true })
      .click();
    await expect(aboveHeaderCard).toContainText("Enabled", { timeout: 15000 });

    /**
     * The slot now renders on the site, above the header, for the ad unit
     * picked in the wizard.
     */
    await goToUncached(page, "/");
    await expect(aboveHeaderSlot).toBeVisible();
    // The slot holds a Google Publisher Tag div, which is what a creative would
    // fill. The ad unit it was pointed at is named in a server-rendered comment
    // beside it, so read the markup for that -- a comment is not text content.
    await expect(aboveHeaderSlot.locator('div[id^="div-gpt-ad-"]')).toHaveCount(
      1
    );
    await expect
      .poll(async () => await aboveHeaderSlot.innerHTML())
      .toContain(`//${AD_UNIT_CODE}`);

    /**
     * Clean up: disable the placement and the provider. Re-asserting that the
     * slot disappears proves the assertion above tracks the wizard setting
     * rather than passing against some fixed page state.
     */
    await page.goto(PLACEMENTS_URL);
    await aboveHeaderCard.getByRole("button", { name: "Edit" }).click();
    await aboveHeaderCard.getByRole("button", { name: "Disable" }).click();
    await expect(
      aboveHeaderCard.getByRole("button", { name: "Enable" })
    ).toBeVisible({ timeout: 15000 });

    await goToUncached(page, "/");
    await expect(aboveHeaderSlot).toHaveCount(0);

    await page.goto(PROVIDERS_URL);
    await setGamProvider(false);
    await expect(page.getByText("No credentials provided.")).toHaveCount(0, {
      timeout: 15000,
    });
  }
);
