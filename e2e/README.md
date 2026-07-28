# Newspack end-to-end testing

## Setup

### Local setup & testing

Will need a local test site – set it up with [`newspack-docker`](https://github.com/Automattic/newspack-docker) by running `n sites-add e2e`. This will create a local `https://e2e.local` site. The provisioning script (`site-setup.sh`) ships in this repo, but the site must have the Newspack plugins the suite activates installed (`newspack-plugin`, `newspack-blocks`, `newspack-popups`, `newspack-ads`, `newspack-newsletters`, `newspack-manager`) and, for the `@with-woo` tests, the WooCommerce stack (`woocommerce`, `woocommerce-subscriptions`, `woocommerce-gateway-stripe`, `woocommerce-memberships`, `woocommerce-name-your-price`).

1. One-time setup (unless the files mentioned below are updated)
   - create an `.env` file (see `.env-sample`).
   - set up payments - see "Payments" section below

   Provisioning deploys `e2e-plugin.php` onto the site from this repo on every run,
   so there is no separate step to install it (and no stale copy to keep in sync).
2. Testing
   - run `npm run test:setup` for a full run that provisions the site before each phase
   - run `npm t` to run the specs against the site's current state (no provisioning)
   - run `npm run test:ui` for a test run with UI
   - run `npm run codegen -- <site-url>` for a test code generation UI

### CI testing

The suite runs nightly (~07:00 UTC) on TeamCity, build configuration
`Newspack_E2eTests` (project `Newspack_E2ETests`), against the Atomic staging
site `https://e2e.newspackstaging.com`. The build definition lives in TeamCity
settings, not in this repo; its steps are: install dependencies, write a `.env`,
then run `npm run test:setup` (the setup projects provision the site over SSH).

[Credentials for the Atomic site used for the e2e testing.](https://mc.a8c.com/secret-store/?secret_id=12168)

The build is parameterised by these variables (set in TeamCity, not committed):

1. `E2E_SITE_URL`, `E2E_WP_USERNAME`, `E2E_WP_PASSWORD` – the target site and its
   admin login. These are written into `.env` as `SITE_URL`, `ADMIN_USER` and
   `ADMIN_PASSWORD`. Provisioning reinstalls WordPress with these credentials, so
   they define the admin login (there is no captured password to match).
2. `E2E_SSH_HOST`, `E2E_SSH_USER`, `E2E_SSH_USER_PASS` – SSH
   access to the site, read by `setupSite` (`tests/site-setup.ts`) to run the
   provisioning script remotely. Optionally `E2E_REMOTE_WP_PATH` (defaults to
   `htdocs`). Password auth uses `sshpass`, which must be on the agent's PATH.
3. `STRIPE_PUB_KEY`, `STRIPE_SECRET_KEY` – Stripe test-mode keys (TeamCity env
   variables). `setupSite` forwards them to the remote provisioning, which applies
   them to the WooCommerce Stripe gateway so the `@with-woo` donation test can
   complete. See also the "Payments" section below.

### Payments

1. Configure the Stripe gateway to use the WC Connect Stripe gateway version (*not* the "Legacy checkout experience").
1. Make sure Stripe "Link by Stripe" express checkout (in "Payment Methods") is disabled

## How provisioning works

With `npm run test:setup` (`USE_SETUP=true`) the runner rebuilds the site from
scratch before each phase instead of restoring a saved fixture: a **vanilla**
Newspack site for the `@vanilla` tests, then the same site re-provisioned **with
WooCommerce** for the `@with-woo` tests. Because the site is rebuilt against the
currently installed plugin code every run, there is no fixture to drift out of date.

The provisioning entry point is `e2e-setup.sh`, which runs the generic
`site-setup.sh` bootstrap (both ship in this repo) and layers e2e-specific config.
`setupSite` (`tests/site-setup.ts`) copies `site-setup.sh` onto the target and runs
`e2e-setup.sh` against it (local `docker exec`, or SSH on CI). See `AGENTS.md` for
the full model.

Because both states run in one pass, the tests run in a fixed order. The
dependency chain in `playwright.config.ts` is a bit involved, but the order is:
```
USE_SETUP is TRUE (with workers: 1):

🔧 SETUP PHASE
┌─────────────────┐
│  setup-vanilla  │  (provisions a vanilla site)
└─────────┬───────┘
          │
🧪 VANILLA TESTS PHASE
          ▼
┌─────────────────┐
│ Vanilla Desktop │  (@vanilla tests)
│     Chrome      │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐
│ Vanilla Mobile  │  (@vanilla tests)
│     Chrome      │  ← LAST @vanilla test
└─────────┬───────┘
          │
🔧 WOO SETUP PHASE
          ▼
┌─────────────────┐
│ setup-with-woo  │  (re-provisions with WooCommerce)
└─────────┬───────┘
          │
🧪 WOO TESTS PHASE
          ▼
┌─────────────────┐
│ With Woo Desktop│  (@with-woo tests. Depends on setup-with-woo that in turn depends on Vanilla in Mobile Chrome to finish)
│     Chrome      │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐
│ With Woo Mobile │  (@with-woo tests)
│     Chrome      │
└─────────────────┘
```

## Writing tests

Tests can be written by hand in the `tests` directory, or with the help of Playwright codegen. To use the latter option, run `npm run codegen -- <site-url>`. When you're done, copy and paste the code to `tests/<test-name>.spec.js`, adjust, and submit the changes in a PR.

Tag tests `@vanilla` or `@with-woo` so they run in the matching phase. If a test
needs a particular fixture (a page, product, user, …), have `e2e-setup.sh` (or the
underlying `site-setup.sh`) create it, so it's rebuilt on every run.

## Provisioning the test site manually

`e2e-setup.sh` and `site-setup.sh` can be run by hand. Copy them, plus
`e2e-plugin.php`, into the site's WordPress root (they must sit together so
`e2e-setup.sh` finds `site-setup.sh` and `e2e-plugin.php` next to it), then,
inside the local Docker container:
```
docker cp site-setup.sh  <container>:/var/www/html/
docker cp e2e-setup.sh   <container>:/var/www/html/
docker cp e2e-plugin.php <container>:/var/www/html/
docker exec -i <container> bash /var/www/html/e2e-setup.sh --woo --allow-root \
  --url <site-url> --admin-user admin --admin-password password
```
Use `--no-woo` for the vanilla state. For the Stripe gateway (needed by
`@with-woo` checkout tests such as `donations.spec.ts`) provide the test keys via
`.env` or the environment:
```
STRIPE_PUB_KEY=<the-pub-key>
STRIPE_SECRET_KEY=<the-secret-key>
```
Without them the Stripe gateway is left unconfigured. Tests that only exercise the
reader/account flow (e.g. `reader-registration.spec.ts`) do not need them.
