import { expect, Page } from "@playwright/test";
import { goToAdminMenu } from "./utils-admin";

// Shared helpers for the Access Control wizard, used by the content gating
// and premium newsletters specs. Premium newsletters reuse the content-gate
// machinery (same edit screen, save panel, and More menu), so everything here
// works on both wizards.

// The gate rendered on the front-end (inline or overlay variant). It is
// removed from the DOM entirely when the reader is allowed through.
export const getGate = (page: Page) =>
  page.locator(".newspack-content-gate__gate").first();

// Collect permalinks of the latest posts from the homepage. Scraped in the
// browser rather than fetched over the REST API so the request goes through
// the same trust store as every other browser navigation.
export const getPostUrls = async (page: Page): Promise<string[]> => {
  await page.goto("/");
  const urls = await page
    .locator("article .entry-title a")
    .evaluateAll((links) =>
      links.map((link) => (link as HTMLAnchorElement).href)
    );
  expect(urls.length).toBeGreaterThanOrEqual(2);
  return urls;
};

// Navigate to the Access control wizard and start a new all-posts gate. The
// list screen shows an onboarding empty state when no gates exist and a
// regular list otherwise; on a narrow viewport the list's "Add new content
// gate" header action is hidden and lives in the header's More dropdown
// instead. Handle all three entry points.
export const startNewGate = async (page: Page) => {
  await goToAdminMenu("Audience", "Access control", page);
  await clickAddNewGate(page, "Add new content gate");
  await page.waitForURL(/#\/edit\/new/);
};

// Click whichever "start a new gate" affordance the current state and
// viewport offer: the onboarding card (empty state), the header link (list),
// or -- on narrow viewports, where that link is hidden -- the equivalent item
// in the header's More dropdown. The wizard renders its pieces asynchronously
// (the h1 can be up before the cards mount), so retry the whole decision
// until one of the targets is actually clickable rather than branching on a
// single visibility snapshot.
export const clickAddNewGate = async (page: Page, addNewLabel: string) => {
  const onboardingCard = page.getByRole("link", { name: /Restrict all/ });
  const addNewLink = page.getByRole("link", { name: addNewLabel });
  await expect(async () => {
    if (await onboardingCard.isVisible()) {
      return await onboardingCard.click();
    }
    if (await addNewLink.isVisible()) {
      return await addNewLink.click();
    }
    const moreToggle = page
      .getByRole("button", { name: "More", exact: true })
      .first();
    if (await moreToggle.isVisible()) {
      if ((await moreToggle.getAttribute("aria-expanded")) !== "true") {
        await moreToggle.click();
      }
      return await page
        .getByRole("menuitem", { name: addNewLabel })
        .click({ timeout: 2000 });
    }
    throw new Error("No add-new-gate entry point rendered yet.");
  }).toPass({ timeout: 30000 });
};

// Open the wizard header's More dropdown if it isn't open already. The header
// persists across the wizard's SPA routes, so the dropdown can still be open
// from a previous interaction -- a blind click would toggle it shut.
const openHeaderMoreMenu = async (page: Page) => {
  const moreToggle = page
    .getByRole("button", { name: "More", exact: true })
    .first();
  if ((await moreToggle.getAttribute("aria-expanded")) !== "true") {
    await moreToggle.click();
  }
};

// Trigger the wizard header's Save action. On desktop it's a header button;
// on narrow viewports that button is hidden and Save moves into the header's
// More dropdown as a menu item.
const clickHeaderSave = async (page: Page) => {
  const headerSave = page.getByRole("button", { name: "Save", exact: true });
  if (await headerSave.isVisible()) {
    await headerSave.click();
  } else {
    await openHeaderMoreMenu(page);
    await page.getByRole("menuitem", { name: "Save" }).click();
  }
};

// Save the gate being edited and set it live via the save panel ("Are you
// ready to save?"). New gates default to Inactive in the panel, so explicitly
// pick Active.
export const saveGateAsActive = async (page: Page) => {
  await clickHeaderSave(page);
  const saveDialog = page.getByRole("dialog");
  await expect(saveDialog.getByText("Are you ready to save?")).toBeVisible();
  await saveDialog.getByRole("radio", { name: "Active", exact: true }).check();
  await saveDialog.getByRole("button", { name: "Save", exact: true }).click();
  await page.waitForURL(/#\/content-gates/);
};

// Delete a gate from its edit screen's More menu and confirm. Expects to be
// on the gate list screen of either wizard.
export const deleteGate = async (page: Page, gateName: string) => {
  await page.getByRole("link", { name: gateName, exact: true }).click();
  await page.waitForURL(/#\/edit\/\d+/);
  // The hash updates before React swaps the view; wait for the edit screen's
  // h1 (the gate name) so the list -- with its own More buttons -- is gone
  // before targeting the header's More menu.
  await expect(
    page.getByRole("heading", { level: 1, name: new RegExp(gateName) })
  ).toBeVisible();
  await openHeaderMoreMenu(page);
  await page.getByRole("menuitem", { name: "Delete" }).click();
  const confirmDialog = page.getByRole("dialog");
  await expect(confirmDialog.getByText("Are you sure?")).toBeVisible();
  await confirmDialog.getByRole("button", { name: "Delete" }).click();
  await page.waitForURL(/#\/content-gates/);
  await expect(
    page.getByRole("link", { name: gateName, exact: true })
  ).toBeHidden();
};
