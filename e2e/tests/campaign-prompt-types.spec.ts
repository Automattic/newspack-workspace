import { test, expect } from "@playwright/test";
import { logIn, goToAdminMenu, getEditorCanvas } from "./utils-admin";
import { randomString } from "./utils";

test(
  "Create and verify different prompt types",
  {
    tag: ["@vanilla", "@with-woo"],
  },
  async ({ page }) => {
    await logIn(page);

    await goToAdminMenu("Audience", "Campaigns", page);

    // Create a campaign for the test.
    await page.getByRole("button", { name: "Add New Campaign" }).click();
    await page.getByPlaceholder("Campaign Name").fill("Prompt Types Test");
    await page.getByRole("button", { name: "Add" }).click();
    await page.waitForURL("**/campaigns/**");

    // --- Part 1: Above Header prompt ---

    const aboveHeaderId = randomString(4);
    const aboveHeaderTitle = `Above Header #${aboveHeaderId}`;
    const aboveHeaderBody = `Above header content (#${aboveHeaderId})`;

    await page.getByRole("button", { name: "Add New Prompt" }).click();
    await page.getByRole("link", { name: "Above Header" }).click();
    await page.waitForURL(/post_type=newspack_popups_cpt/);

    // Fill in the prompt content.
    let editor = await getEditorCanvas(page);
    await editor.getByLabel("Add title").fill(aboveHeaderTitle);
    await editor.getByLabel("Add default block").click();
    await editor.getByLabel("Empty block; start writing or").fill(aboveHeaderBody);

    // Above Header prompts don't have a delay setting -- just publish directly.

    // Publish the prompt.
    await page.getByRole("button", { name: "Publish", exact: true }).click();
    await page
      .getByLabel("Editor publish")
      .getByRole("button", { name: "Publish", exact: true })
      .click();
    await expect(
      page.getByTestId("snackbar").getByText("Post published.")
    ).toBeVisible();

    // Verify the above-header prompt on the front end.
    await page.goto("/");
    await expect(page.getByText(aboveHeaderBody)).toBeVisible();

    // Delete the above-header prompt.
    await goToAdminMenu("Audience", "Campaigns", page);
    await page.getByLabel("More options").click();
    await page.getByRole("menuitem", { name: "Delete" }).click();

    // --- Part 2: Inline prompt ---

    const inlineId = randomString(4);
    const inlineTitle = `Inline #${inlineId}`;
    const inlineBody = `Inline content (#${inlineId})`;

    await page.getByRole("button", { name: "Add New Prompt" }).click();
    await page.getByRole("link", { name: "Inline" }).click();
    await page.waitForURL(/post_type=newspack_popups_cpt/);

    // Fill in the prompt content.
    editor = await getEditorCanvas(page);
    await editor.getByLabel("Add title").fill(inlineTitle);
    await editor.getByLabel("Add default block").click();
    await editor.getByLabel("Empty block; start writing or").fill(inlineBody);

    // Inline prompts don't have a delay setting -- just publish directly.

    // Publish the prompt.
    await page.getByRole("button", { name: "Publish", exact: true }).click();
    await page
      .getByLabel("Editor publish")
      .getByRole("button", { name: "Publish", exact: true })
      .click();
    await expect(
      page.getByTestId("snackbar").getByText("Post published.")
    ).toBeVisible();

    // Verify the inline prompt on the front end. Unlike the above-header prompt,
    // an inline prompt renders within the content flow and so starts below the
    // fold: scroll to it and assert it actually lands in the viewport, which
    // toBeVisible() alone would not catch (it ignores scroll position).
    await page.goto("/");
    const inlinePrompt = page.getByText(inlineBody);
    await expect(inlinePrompt).toBeVisible();
    await inlinePrompt.scrollIntoViewIfNeeded();
    await expect(inlinePrompt).toBeInViewport();

    // Clean up: delete the inline prompt and campaign.
    await goToAdminMenu("Audience", "Campaigns", page);
    await page.getByLabel("More options").click();
    await page.getByRole("menuitem", { name: "Delete" }).click();
  }
);
