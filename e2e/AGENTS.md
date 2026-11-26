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

## CI (TeamCity)

TeamCity runs the suite; the build definitions live in TeamCity settings, not this
repo. Provisioning is over SSH using the `E2E_SSH_HOST` / `E2E_SSH_USER` /
`E2E_SSH_USER_PASS` credentials that `setupSite` reads for its remote path. A managed
host (Atomic) cannot `DROP DATABASE`, so the remote path uses `--reset clean` (drop
tables, keep the DB).

Two kinds of run:

- **Nightly** — against the shared staging site, testing its installed release build.
  The broad daily signal.
- **Release gate** — on a PR into `alpha` or `release`, testing that PR's own built
  code against a dedicated per-branch staging site, so a regression is caught before
  it promotes. Each gated branch has its own site, so runs never collide; two PRs
  into the same branch serialise on that site via the build queue.

### Release-gate build (per gated branch)

The gate build differs from the nightly only in that it deploys the candidate build
before provisioning:

1. Trigger on the PR's **target branch** (TeamCity's Pull Requests build feature
   filters by target branch); build the PR's merge commit.
2. Build the plugins deploy-ready — the asset build **and** `composer install
   --no-dev` for plugins that ship a `vendor/` — then run `e2e/deploy-plugins.sh`
   to push them onto the branch's site over the same `E2E_SSH_*` channel. It swaps
   each plugin/theme in place (staging and verifying the new copy before displacing
   the live one), and refuses to ship a plugin whose asset build or `vendor/` is
   missing.
3. `USE_SETUP=true npm run test:setup` — `setupSite` provisions from scratch against
   the deployed code, then the suite runs.
4. Report the result to the PR as a status check (Commit Status Publisher).

The gate build supplies the same secrets as the nightly — the `E2E_SSH_*` SSH
credentials and `ADMIN_PASSWORD` — plus a strong `E2E_EMAIL_SENDBOX_SECRET`:
provisioning refuses the default sendbox secret on an internet-reachable host, and
the suite reads `/_email` with the same value.

`deploy-plugins.sh` deploys only the plugins/theme whose source is in this monorepo
(`newspack-plugin`, `-blocks`, `-popups`, `-newsletters`, `-ads`, and the
`newspack-theme` classic theme). `newspack-manager` ships from its own repo and is
expected already installed on the site.

The specs track the release-channel UI (see the setup-model note above). On the
`alpha` gate they run against alpha code, so a spec that no longer matches alpha's UI
fails there first — the gate flagging a spec to update before the feature promotes,
not a reason to branch the spec.
