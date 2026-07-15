
// Return the locator scope for the block editor content. Gutenberg iframes the
// editor canvas in some configurations (block themes, newer Gutenberg) but not
// others (the classic newspack-theme renders blocks at the top level), so
// detect it and fall back to the page when there's no canvas iframe.
export const getEditorCanvas = async (page) => {
  // Wait for the editor root to exist. Use "attached" rather than the default
  // "visible": on a mobile viewport #editor is a wrapper that doesn't pass the
  // visibility check even though the editor has loaded.
  await page.locator("#editor").waitFor({ state: "attached" });
  // The canvas iframe (block themes / newer Gutenberg) mounts asynchronously;
  // give it a brief chance to appear before falling back to the page.
  const isIframed = await page
    .locator('iframe[name="editor-canvas"]')
    .waitFor({ state: "attached", timeout: 5000 })
    .then(() => true)
    .catch(() => false);
  return isIframed ? page.frameLocator('iframe[name="editor-canvas"]') : page;
};

// Log in to the admin dashboard.
export const logIn = async (page) => {
  await page.goto("/wp-login.php");
  await page.waitForTimeout(500); // Prevent a weird issue where the inputs are cleared after clicking the button.
  await page.getByLabel("Username or Email Address").click();
  await page
    .getByLabel("Username or Email Address")
    .fill(process.env.ADMIN_USER);
  await page.getByLabel("Password", { exact: true }).click();
  await page
    .getByLabel("Password", { exact: true })
    .fill(process.env.ADMIN_PASSWORD);
  await page.getByRole("button", { name: "Log In" }).click();
  await page.waitForURL(/\/wp-admin/);
};

export const logOut = async (page) => {
  await page.goto("/?action=logout_without_nonce");
};

export const isMobileAdmin = async (page) => {
  return await page.getByRole("menuitem", { name: "Menu" }).isVisible();
};

export const goToAdminMenu = async (menuItem, submenuItem, page) => {
  await page.goto("/wp-admin");
  const isMobile = await isMobileAdmin(page);
  if (isMobile) {
    await page.getByRole("menuitem", { name: "Menu" }).click();
  }
  await page
    .getByLabel("Main menu", { exact: true })
    .getByRole("link", { name: menuItem })
    .click();
  await page.getByRole("link", { name: submenuItem, exact: true }).click();
};