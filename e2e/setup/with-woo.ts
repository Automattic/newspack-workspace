import { setupSite } from "../tests/site-setup";
import { test } from "@playwright/test";

test("Setup With Woo", async () => {
  // Provision a Newspack site with the full WooCommerce stack for the @with-woo tests.
  setupSite({ woo: true });
});
