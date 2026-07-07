import { test, expect } from "@playwright/test";
import { logIn, goToAdminMenu, getEditorCanvas } from "./utils-admin";
import { randomString } from "./utils";

test("Create and view a prompt",  {
      tag: ['@vanilla', '@with-woo'],
    },
    async ({page}) => {
  await logIn(page);

  await goToAdminMenu("Audience", "Campaigns", page);

  await expect(page.getByRole("heading", { name: "Everyone" })).toBeVisible();
  await page.getByRole("button", { name: "Add New Campaign" }).click();
  await page.getByPlaceholder("Campaign Name").fill("Basic");
  await page.getByRole("button", { name: "Add" }).click();
  await page.waitForURL("**/campaigns/**");

  await page.getByRole("button", { name: "Add New Prompt" }).click();
  await page.getByRole("link", { name: "Center Overlay Fixed at the" }).click();
  await page.waitForURL(/post_type=newspack_popups_cpt/);

  const editor = await getEditorCanvas(page);

  // Create the prompt.
  const randomId = randomString(4);
  const campaignBody = `This is prompt content (#${randomId})`;
  const campaignTitle = `Prompt #${randomId}`;
  await editor.getByLabel("Add title").fill(campaignTitle);
  await editor.getByLabel("Add default block").click();
  await editor.getByLabel("Empty block; start writing or").fill(campaignBody);

  // The Settings sidebar may be collapsed by default depending on user prefs
  // (always on mobile; sometimes on desktop). Open it via the top-bar toggle,
  // scoped to the editor top bar so DOM order can't pick a different "Settings"
  // control.
  const promptTab = page.getByRole("tab", { name: "Prompt" });
  if (!(await promptTab.isVisible())) {
    await page
      .getByRole("region", { name: "Editor top bar" })
      .getByRole("button", { name: "Settings", exact: true })
      .click();
  }
  await promptTab.click();

  // Inside the Prompt panel the "Settings" group (which contains "Delay") may
  // start collapsed; expand it if so. Scope to the Prompt tabpanel so this
  // targets the group expander, not the top-bar toggle or another panel.
  const promptPanel = page.getByRole("tabpanel", { name: "Prompt" });
  const delayInput = page.getByRole("spinbutton", { name: "Delay (seconds)" });
  if (!(await delayInput.isVisible())) {
    await promptPanel
      .getByRole("button", { name: "Settings", exact: true })
      .click();
  }
  await delayInput.fill("1");

  // Preview the prompt.
  await page.getByRole("button", { name: "Preview" }).click();
  const previewFrame = page.frameLocator('iframe[title="web-preview"]');
  await expect(
    previewFrame.getByRole("button", { name: `draft ${campaignBody}` })
  ).toBeVisible();
  await expect(previewFrame.getByText(campaignBody)).toBeVisible();
  await page.getByLabel("Close Preview").click();

  // Publish the prompt.
  await page.getByRole("button", { name: "Publish", exact: true }).click();
  await page
    .getByLabel("Editor publish")
    .getByRole("button", { name: "Publish", exact: true })
    .click();
  await expect(
    page.getByTestId("snackbar").getByText("Post published.")
  ).toBeVisible();

  // Go to the front-end and verify the prompt is visible.
  await page.goto("/");
  await expect(page.getByText(campaignBody)).toBeVisible();
  await page
    .getByRole("button", {
      name: `${campaignBody} Close Pop-up`,
    })
    .getByLabel("Close Pop-up")
    .click();
  await expect(page.getByText(campaignBody)).not.toBeVisible();

  // Delete the prompt.
  await goToAdminMenu("Audience", "Campaigns", page);
  await page.getByLabel("More options").click();
  await page.getByRole("menuitem", { name: "Delete" }).click();
  await expect(
    page.getByText("No active prompts in this segment.")
  ).toBeVisible();
});
