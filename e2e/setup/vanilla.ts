import { setupSite } from "../tests/site-setup";
import { test } from "@playwright/test";

test("Setup Vanilla", async () => {
  // Provision a vanilla Newspack site (no WooCommerce) for the @vanilla tests.
  setupSite({ woo: false });
});
