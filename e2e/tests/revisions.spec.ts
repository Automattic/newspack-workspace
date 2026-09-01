import { test, expect } from "@playwright/test";

import { logIn, getEditorCanvas } from "./utils-admin";
import { randomString } from "./utils";

// Revisions control lets a publisher mark a revision as "major" so it survives
// the retention limit that deletes older revisions. The controls live on the
// classic revisions screen and are added by a plugin script, so this spec doubles
// as the guard for that script actually being built and enqueued: when it 404s,
// the screen still renders and only the Newspack controls silently disappear.
test(
  "Mark a revision as major",
  {
    tag: ["@vanilla"],
  },
  async ({ page }) => {
    await logIn(page);

    // Publish a post, then edit it, so it has a revision to mark.
    await page.goto("/wp-admin/post-new.php");
    const editor = await getEditorCanvas(page);
    const postTitle = `Revisions test #${randomString(4)}`;
    await editor.getByLabel("Add title").fill(postTitle);
    await editor
      .getByLabel("Empty block; start writing or")
      .fill("First version.");

    await page.getByRole("button", { name: "Publish", exact: true }).click();
    await page
      .getByLabel("Editor publish")
      .getByRole("button", { name: "Publish", exact: true })
      .click();
    await expect(
      page.getByTestId("snackbar").getByText("Post published.")
    ).toBeVisible();

    // Reopen the post rather than editing on: the post-publish panel covers the
    // canvas until it is dismissed, and on a mobile viewport it covers all of it.
    const postId = new URL(page.url()).searchParams.get("post");
    await page.goto(`/wp-admin/post.php?post=${postId}&action=edit`);
    const reopenedEditor = await getEditorCanvas(page);
    await reopenedEditor.getByText("First version.").click();
    await page.keyboard.type(" Second version.");
    // Save with the keyboard rather than the header button: the button's label
    // moves between WordPress versions, the shortcut doesn't.
    await page.keyboard.press("ControlOrMeta+s");
    await expect(
      page.getByTestId("snackbar").getByText("Post updated.")
    ).toBeVisible();

    // The classic revisions screen is addressed by revision ID. Ask the REST API
    // through the editor's own client: the editor's revisions entry point has
    // changed shape across WordPress versions, this hasn't.
    const revisionId = await page.evaluate(async (id) => {
      const revisions = await (window as any).wp.apiFetch({
        path: `/wp/v2/posts/${id}/revisions?per_page=1`,
      });
      return revisions[0].id;
    }, postId);

    await page.goto(`/wp-admin/revision.php?revision=${revisionId}`);

    const markButton = page.getByRole("button", {
      name: "Mark as a major revision",
    });
    const unmarkButton = page.getByRole("button", {
      name: "Unmark as a major revision",
    });
    const majorLabel = page.getByText("Major revision", { exact: true });

    await expect(markButton).toBeVisible();
    await expect(majorLabel).not.toBeVisible();

    await markButton.click();
    await expect(unmarkButton).toBeVisible();
    await expect(majorLabel).toBeVisible();

    // Reload to prove the mark was stored, not just reflected in the page state.
    await page.reload();
    await expect(unmarkButton).toBeVisible();
    await expect(majorLabel).toBeVisible();

    await unmarkButton.click();
    await expect(markButton).toBeVisible();
    await page.reload();
    await expect(markButton).toBeVisible();
    await expect(majorLabel).not.toBeVisible();
  }
);
