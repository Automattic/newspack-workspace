import { test, expect } from "@playwright/test";
import {
  addClickIndicator,
  randomString,
  openEmail,
  clickLinkURL,
  randomEmailAddress,
  clickMyAccountMenuItem,
  goToMyAccount,
} from "./utils";

const emailAddress = randomEmailAddress();

test.beforeEach(addClickIndicator);

test("Register on the site", {
      tag: '@with-woo',
    },
    async ({page}) => {
  // This exercises the full reader lifecycle (register, OTP sign-in, profile
  // edit, password set, password sign-in, email change) with three email
  // round-trips against remote staging, where CI also applies slowMo. The
  // default 120s is too tight once staging latency varies, so allow more room.
  test.setTimeout(240000);
  /**
   * Create a new reader account using the "Sign In" header link. The auth modal
   * is a single email-first form: entering an email that isn't associated with
   * an account registers and signs in the reader, then offers an email
   * verification step which we dismiss (it is exercised on its own below).
   */
  await page.goto("/");
  await page.getByRole("link", { name: "Sign In" }).click();
  await page
    .getByPlaceholder("Your email address", { exact: true })
    .fill(emailAddress);
  await page.getByRole("button", { name: "Continue" }).click();
  const verifyEmailModal = page.getByRole("heading", {
    name: "Verify your email",
  });
  await expect(verifyEmailModal).toBeVisible();
  await page.getByRole("button", { name: "Close" }).click();
  await expect(verifyEmailModal).toBeHidden();
  await goToMyAccount(page);
  await clickMyAccountMenuItem(page, "Sign out");

  /**
   * Log in as the previously created reader.
   */
  await page.goto("/");
  await page.getByRole("link", { name: "Sign In" }).click();
  await page
    .getByPlaceholder("Your email address", { exact: true })
    .fill(emailAddress);
  await page.getByRole("button", { name: "Continue" }).click();
  await expect(page.getByLabel("Sign in").locator("form")).toContainText(
    "Enter the code sent to your email"
  );

  /**
   * Go to the email client to get the log in link.
   */
  await openEmail(page, "Sign in", emailAddress);
  await clickLinkURL(page, "Continue to");

  /**
   * Now the user is authenticated via the magic link, they can update their name.
   */
  await goToMyAccount(page);
  await page.getByPlaceholder("Your First Name").click();
  await page.getByPlaceholder("Your First Name").fill("John");
  await page.getByPlaceholder("Your Last Name").click();
  await page.getByPlaceholder("Your Last Name").fill("Doe");
  await page.getByRole("button", { name: "Update profile" }).click();
  await expect(page.getByText("Account details changed successfully.")).toBeVisible();
  await expect(page.getByPlaceholder("Your First Name")).toHaveValue("John");
  await expect(page.getByPlaceholder("Your Last Name")).toHaveValue("Doe");

  /**
   * Reader sets up a password.
   */
  await page
    .getByRole("link", { name: "Create a password" })
    .click();
  await expect(
    page.getByText(
      "Please check your email inbox for instructions on how to set a new password."
    )
  ).toBeVisible();
  await openEmail(page, "Set a new password", emailAddress);
  await clickLinkURL(page, "Set password");

  const password = randomString(14);
  await page
    .getByLabel(/New password/)
    .fill(password);
  await page.getByLabel(/Re-enter new password/).fill(password);
  await page.getByRole("button", { name: "Save password" }).click();
  await clickMyAccountMenuItem(page, "Sign out");

  /**
   * Reader logs in using the password.
   */
  await page.goto("/");
  await page.getByRole("link", { name: "Sign In", exact: true }).click();
  await page
    .getByPlaceholder("Your email address", { exact: true })
    .fill(emailAddress);
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("Enter your password").fill("not the password");
  await page.getByRole("button", { name: "Continue" }).click();
  await expect(page.getByLabel("Sign in").locator("form")).toContainText(
    "Password not recognized, try again."
  );
  await page.getByLabel("Enter your password").fill(password);
  await page.getByRole("button", { name: "Continue" }).click();
  await expect(page.getByRole("strong")).toContainText(
    "Success! You’re signed in."
  );
  await page.getByRole("link", { name: "Continue" }).click();
  await goToMyAccount(page);

  /**
   * Reader updates their email address.
   */
  const newEmailAddress = randomEmailAddress();
  await page.locator("#newspack_account_email").fill(newEmailAddress);
  await page.getByRole("button", { name: "Update profile" }).click();
  const expectedNotification = `A verification email has been sent to ${newEmailAddress}. Please verify to complete the change.`;
  await expect(page.getByText(expectedNotification)).toBeVisible();
  await openEmail(page, "Confirm email change", newEmailAddress);
  await clickLinkURL(page, "Confirm email change");
  await expect(
    page.getByText("Your email address has been successfully updated.")
  ).toBeVisible();
  await expect(page.locator("#newspack_account_email")).toHaveValue(
    newEmailAddress
  );
});
