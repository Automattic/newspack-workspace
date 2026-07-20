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

// Navigate to a front-end URL while bypassing the page cache. Anonymous
// responses are served from Batcache, so a page loaded right after an
// admin-side change can come back stale -- a signed-out assertion would then be
// checking a copy rendered before the change. A unique query string lands in
// its own cache bucket and forces a fresh render.
export const goToUncached = async (page, url) => {
  const separator = url.includes("?") ? "&" : "?";
  await page.goto(
    `${url}${separator}cachebust=${Date.now()}-${randomString(4)}`
  );
};

export const clickLinkURL = async (page, linkText) => {
  const logInElement = await page.getByRole("link", { name: linkText });
  const logInURL = await logInElement.getAttribute("href");
  await page.goto(logInURL);
};

// The modal checkout -- opened by the Donate block, the Checkout Button block
// and anything else that sells a product -- renders in its own iframe.
export const getModalCheckout = (page) =>
  page.frameLocator('iframe[name="newspack_modal_checkout_iframe"]');

// The card fields are a Stripe Elements iframe nested inside the modal checkout.
// Stripe renders an extra aria-hidden "Secure payment input frame" (the ACH
// bank-search results frame) alongside the card input frame, so exclude hidden
// frames to keep this matching a single element.
export const getStripeCardFields = (page) =>
  getModalCheckout(page).frameLocator(
    `[data-payment-method-type="card"] [title="Secure payment input frame"]:not([aria-hidden="true"])`
  );

// Pay with Stripe's test card. The site runs on Stripe test keys, so this is the
// card that always succeeds.
export const fillStripeTestCard = async (page) => {
  const cardFields = getStripeCardFields(page);
  await cardFields
    .getByPlaceholder("1234 1234 1234 1234")
    .fill("4242 4242 4242 4242");
  await cardFields.getByPlaceholder("MM / YY").fill("04 / 44");
  await cardFields.getByLabel("Security code").fill("333");

  // Depending on geo, Stripe may want a ZIP code, too.
  const zipCode = cardFields.getByPlaceholder("12345");
  if (await zipCode.isVisible()) {
    await zipCode.fill("12345");
  }
};

// Fill the modal checkout's billing step and move on to payment.
export const fillModalCheckoutBillingDetails = async (page, emailAddress) => {
  const modalCheckout = getModalCheckout(page);
  await modalCheckout.getByLabel("Email address *").fill(emailAddress);
  await modalCheckout.getByLabel("First name *").fill("John");
  await modalCheckout.getByLabel("Last name *").fill("Doe");
  await modalCheckout.getByRole("button", { name: "Continue" }).click();
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

  // On a phone the account menu is a drawer parked off-screen, so the item has
  // to be revealed before it can be clicked. An unrevealed item still counts as
  // visible -- it has a box and is not hidden -- it just sits beyond the
  // viewport with no scroll that can reach it, so a plain click retries until
  // it times out.
  //
  // Retry the whole reveal-and-click rather than deciding once up front: some
  // callers arrive mid-navigation (e.g. straight after "Save password"), and a
  // single check against the transitional page finds no nav toggle, so the
  // drawer stays shut and the item stays unreachable. The toggle renames itself
  // to "Close navigation" once open, so retrying can never close it again.
  await expect(async () => {
    if (await isMobile(page)) {
      await page.getByRole("button", { name: "Open navigation" }).click();
    }
    await link.click({ timeout: 5000 });
  }).toPass({ timeout: 30000 });
};
