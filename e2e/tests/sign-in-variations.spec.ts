import { test, expect } from "@playwright/test";
import {
  addClickIndicator,
  randomString,
  openEmail,
  clickLinkURL,
  randomEmailAddress,
  clickMyAccountMenuItem,
} from "./utils";

const emailAddress = randomEmailAddress();

test.beforeEach(addClickIndicator);

test(
  "Wrong password, reset, and sign in with new password",
  {
    tag: "@with-woo",
  },
  async ({ page }) => {
    /**
     * Register a new account via the header Sign In flow.
     */
    await page.goto("/");
    await page.getByRole("link", { name: "Sign In" }).click();
    await page.getByRole("button", { name: "Create an account" }).click();
    await page
      .getByPlaceholder("Your email address", { exact: true }).first()
      .fill(emailAddress);
    await page
      .locator(".newspack-ui__modal")
      .getByRole("button", { name: "Continue" })
      .click();
    await expect(page.getByRole("strong")).toContainText(
      /Success! Your account was created and you.re signed in\./
    );
    await page.getByRole("link", { name: "Continue" }).click();

    /**
     * Create a password via "Set a new password" on My Account.
     */
    await page.locator(".newspack-reader__account-link:visible").first().click();
    await page.waitForURL(/my-account/);
    await page.getByText("Set a new password").click();
    await expect(
      page.getByText(
        "Please check your email inbox for instructions on how to set a new password."
      )
    ).toBeVisible();

    await openEmail(page, "Set a new password", emailAddress);
    await clickLinkURL(page, "Set password");

    const originalPassword = randomString(14);
    await page.getByLabel(/New password/).fill(originalPassword);
    await page.getByLabel(/Re-enter new password/).fill(originalPassword);
    await page.getByRole("button", { name: "Save password" }).click();
    await clickMyAccountMenuItem(page, "Sign out");

    /**
     * Try to sign in with a wrong password and verify the error.
     */
    await page.goto("/");
    await page.getByRole("link", { name: "Sign In", exact: true }).click();
    await page
      .getByPlaceholder("Your email address", { exact: true }).first()
      .fill(emailAddress);
    await page
      .locator(".newspack-ui__modal")
      .getByRole("button", { name: "Continue" })
      .click();
    await page.getByLabel("Enter your password").fill("wrong-password-attempt");
    await page
      .locator(".newspack-ui__modal")
      .getByRole("button", { name: "Continue" })
      .click();
    await expect(page.getByLabel("Sign in").locator("form")).toContainText(
      "Password not recognized, try again."
    );

    /**
     * Sign in with the correct password.
     */
    await page.getByLabel("Enter your password").fill(originalPassword);
    await page
      .locator(".newspack-ui__modal")
      .getByRole("button", { name: "Continue" })
      .click();
    await expect(page.getByRole("strong")).toContainText(
      /Success! You.re signed in\./
    );
  }
);
