import { expect } from "@playwright/test";

export const randomString = (length = 8) =>
  Math.random()
    .toString(36)
    .substring(2, length + 2);

export const randomEmailAddress = () => `test-${randomString()}@example.com`;

// Open an email in the dev "Email Sendbox" (/_email) by its subject + recipient.
// Emails are saved asynchronously and the sendbox is a static page, so we reload
// until the message shows up instead of trusting a single load (otherwise a
// message that arrives after the page render is never seen).
export const openEmail = async (page, subjectPrefix, emailAddress) => {
  const emailLink = page.getByText(`${subjectPrefix} (${emailAddress}`);
  await expect(async () => {
    await page.goto(`/_email?cachebust=${emailAddress}-${Date.now()}`);
    await expect(emailLink).toBeVisible({ timeout: 1000 });
  }).toPass({ timeout: 30000 });
  await emailLink.click();
};

// Navigate to the reader's My Account page. We go directly rather than clicking
// the header link: that link is collapsed behind the nav toggle on mobile, and
// auth events (registration, magic-link or password sign-in) trigger an
// asynchronous reload that can swallow the click. A direct navigation is
// reliable on every viewport.
export const goToMyAccount = async (page) => {
  await page.goto("/my-account/");
  await page.waitForURL(/my-account/);
};

export const clickLinkURL = async (page, linkText) => {
  const logInElement = await page.getByRole("link", { name: linkText });
  const logInURL = await logInElement.getAttribute("href");
  await page.goto(logInURL);
};

export const addClickIndicator = async ({ page }) => {
  await page.addInitScript(() => {
    document.addEventListener(
      "click",
      (event) => {
        const clickWidth = 30;
        const clickIndicator = document.createElement("div");
        clickIndicator.style.position = "absolute";
        clickIndicator.style.width = `${clickWidth}px`;
        clickIndicator.style.height = `${clickWidth}px`;
        clickIndicator.style.backgroundColor = "red";
        clickIndicator.style.borderRadius = "50%";
        clickIndicator.style.top = `${event.clientY - clickWidth / 2}px`;
        clickIndicator.style.left = `${event.clientX - clickWidth / 2}px`;
        clickIndicator.style.zIndex = "9999";
        clickIndicator.style.pointerEvents = "none";
        clickIndicator.style.transition = "opacity 1s ease-out";
        document.body.appendChild(clickIndicator);

        // Remove the indicator
        setTimeout(() => {
          clickIndicator.style.opacity = "0";
          setTimeout(() => clickIndicator.remove(), 1000);
        }, 1000);
      },
      { capture: true }
    );
  });
};

export const isMobile = async (page) =>
  await page.getByRole("button", { name: "Open navigation" }).isVisible();

export const clickMyAccountMenuItem = async (page, label) => {
  const link = page.getByRole("link", { name: label });
  // Wait for the account page chrome to render before deciding mobile vs.
  // desktop. Some flows navigate asynchronously (e.g. after "Save password"),
  // and evaluating isMobile() against the transitional page returns false, so
  // the mobile nav drawer never opens and the target link stays off-screen.
  await link.waitFor({ state: "attached" });
  if (await isMobile(page)) {
    await page.getByRole("button", { name: "Open navigation" }).click();
  }
  await link.click();
};
