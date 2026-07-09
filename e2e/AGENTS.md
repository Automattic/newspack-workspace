# newspack-e2e-tests – agent notes

Playwright end-to-end suite for Newspack. CI (TeamCity) runs it against
`https://e2e.newspackstaging.com`. It can also run against a local isolated env.

## How to run

```sh
# Local env (default; targets SITE_URL from .env, usually https://e2e-release.test):
npm run test:setup

# A single project (skips the rest):
USE_SETUP=true npx playwright test --project="Vanilla in Desktop Chrome"

# Against staging – override SITE_URL and ADMIN_PASSWORD inline:
SITE_URL="https://e2e.newspackstaging.com" ADMIN_PASSWORD="<staging-pw>" \
  USE_SETUP=true npx playwright test --project="With Woo in Desktop Chrome"
```

- Projects: `setup-vanilla` and `setup-with-woo` provision the site into the state
  their tests expect; the four `Vanilla/With Woo in Desktop/Mobile Chrome` projects
  depend on them (so running a spec project pulls in its setup).
- The local env runs in the docker container `newspack_env_e2e_release`
  (`docker exec newspack_env_e2e_release wp --allow-root ...`).
- `USE_SETUP` gates whether the setup projects (and the dependency chain) run. With
  it unset, `npm test` runs the specs against whatever state the site is already in.

## Site setup model (read this before touching provisioning or the setup phases)

The suite provisions the site from scratch each phase rather than restoring a DB
dump. This keeps it drift-free: the site is always rebuilt against the currently
installed plugin code, so a plugin/core update can't leave a stale fixture behind.

- **`site-setup.sh`** (this repo) is the from-scratch Newspack bootstrap (DB reset +
  fresh install + posts/users/WooCommerce+donations/memberships/subscriptions/
  campaigns/menus). It's a generic dev provisioner, parameterised by `--url`,
  `--admin-*`, `--allow-root`, `--reset`, and the `--no-*` toggles.
- **`e2e-setup.sh`** (this repo) is the entry point. It runs `site-setup.sh` and then
  layers the e2e-specific config that script deliberately omits: the `NEWSPACK_IS_E2E`
  flag, the `e2e-plugin`, the extra Newspack plugins the suite drives
  (ads/newsletters/manager), Stripe test keys, editor preferences, timezone, etc.
  `--woo` / `--no-woo` selects the WooCommerce stack.
- **`tests/site-setup.ts`** (`setupSite`) is how the Playwright setup projects run
  it: it copies `site-setup.sh` and `e2e-plugin.php` onto the target and streams
  `e2e-setup.sh`, pointing it at the copies via `SITE_SETUP_SCRIPT` /
  `E2E_PLUGIN_SRC`. `e2e-setup.sh` then deploys the plugin into the site's plugins
  dir before activating it, so the running plugin always matches the committed
  source. Locally it `docker cp` + `docker exec`s
  into the env container (as root, `--allow-root`, full `wp db reset`); on CI it
  SSHes to the host (no `--allow-root`, `--reset clean` since a managed host can't
  `DROP DATABASE`). Local vs remote is decided from the `SITE_URL` host.
- **Credentials**: `setupSite` reinstalls WordPress with `ADMIN_USER` /
  `ADMIN_PASSWORD` from `.env`, so those are authoritative – provisioning sets the
  admin login, there is no separate captured password to keep in sync. `.env`'s
  `ADMIN_PASSWORD=password` is for the local env; staging's lives in the a8c secret
  store (README → `secret_id=12168`).
- **On-site prerequisites**: the WooCommerce stack for the `--woo` path, and the
  Newspack plugins the suite activates (incl. `newspack-manager`) must be installed.
  `site-setup.sh` and `e2e-plugin.php` are shipped from this repo, not the site –
  provisioning deploys the plugin every run, so it can't drift out of sync.

## CI (TeamCity) notes

The build definition lives in TeamCity settings, not this repo. It provisions over
SSH using the `E2E_SSH_HOST` / `E2E_SSH_USER` / `E2E_SSH_USER_PASS` credentials, which
`setupSite` also reads for the remote path. A managed host (Atomic) cannot
`DROP DATABASE`, so the remote path uses `--reset clean` (drop tables, keep the DB).
