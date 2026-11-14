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
- `E2E_PHASE` (`vanilla` | `woo` | `ac`) and `E2E_VIEWPORT` (`desktop` | `mobile`)
  each select one axis of the run; unset means "all" (`both` is a back-compat alias
  for the phase axis). Every slice provisions and runs on its own, so a slice can
  target its own site independently. The six single-slice scripts wrap the
  combinations:

  ```sh
  npm run test:vanilla:desktop   # E2E_PHASE=vanilla E2E_VIEWPORT=desktop
  npm run test:vanilla:mobile
  npm run test:woo:desktop
  npm run test:woo:mobile
  npm run test:ac:desktop        # Access Control (content gates / paywalls)
  npm run test:ac:mobile
  ```

  The `ac` phase runs the Access-Control specs (tagged `@access-control`:
  `content-gating.spec.ts`, `premium-newsletters.spec.ts`). Those tags are
  deliberately *not* `@vanilla`/`@with-woo`, so the vanilla and woo slices no longer
  run them -- an AC regression can't fail a base or woo build. The AC gates need
  WooCommerce, so the `ac` phase provisions with `setup-with-woo` (same as the woo
  phase). Because each provisioning does a destructive from-scratch reset, two slices
  must not share a site concurrently -- run them against separate sites (see CI notes).

## Site setup model (read this before touching provisioning or the setup phases)

The suite provisions the site from scratch each phase rather than restoring a DB
dump. This keeps it drift-free: the site is always rebuilt against the currently
installed plugin code, so a plugin/core update can't leave a stale fixture behind.

> **Specs run against the *installed* plugin version, and the nightly's site is
> pinned to the stable release channel. Write specs against the release-channel
> UI, and only that.** Specs land on `main` (which tracks `alpha`), so it is
> tempting to drive the newest UI – but a spec that drives a feature not yet
> shipped to stable fails every night until it ships. Don't paper over the gap
> with feature-detection, channel branching, or `test.skip()`: a spec must not
> fork on release vs `alpha`/`main`. If a feature is alpha-only, hold its coverage
> until it reaches the release channel; when that UI later lands on release,
> update the spec to match. The suite's one job is to prove the release channel
> works, so a spec that no longer matches release is a spec to fix, not to branch.

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

### Sliced into parallel build configs

A single build running the whole suite (every phase, both viewports) exceeds
TeamCity's 20-minute per-build execution timeout -- the build is killed at 20:00
before Playwright flushes, reporting "0 passed". So the suite is sliced into
independent build configs, one per `E2E_PHASE` x `E2E_VIEWPORT` combination, each
comfortably under the cap:

| Build config    | `E2E_PHASE` | `E2E_VIEWPORT` | Script                         | Stripe keys |
| --------------- | ----------- | -------------- | ------------------------------ | ----------- |
| base-desktop    | `vanilla`   | `desktop`      | `npm run test:vanilla:desktop` | no          |
| base-mobile     | `vanilla`   | `mobile`       | `npm run test:vanilla:mobile`  | no          |
| woo-desktop     | `woo`       | `desktop`      | `npm run test:woo:desktop`     | yes         |
| woo-mobile      | `woo`       | `mobile`       | `npm run test:woo:mobile`      | yes         |
| ac-desktop      | `ac`        | `desktop`      | `npm run test:ac:desktop`      | yes         |
| ac-mobile       | `ac`        | `mobile`       | `npm run test:ac:mobile`       | yes         |

Each config provisions its own site from scratch (`--reset clean` + full setup),
so they **must each target a different site** -- otherwise a parallel run's reset
would wipe a site another config is mid-test on. Point each config at its own site
via `E2E_SITE_URL` (written to `.env` as `SITE_URL`); every other parameter
(`E2E_SSH_HOST`/`E2E_SSH_USER`/`E2E_SSH_USER_PASS` for that site, Stripe test keys
for the woo/ac configs, admin creds) is set per config the same way the single
build sets them today. With one site per config they run fully in parallel; with
fewer, chain the ones that share a site so they never provision concurrently.

If a single slice ever creeps back toward the 20-minute cap, slice that phase's
specs further (e.g. by tag or file) the same way, rather than lengthening any one
build -- the `ac` phase was itself carved out of the vanilla/woo phases this way.
