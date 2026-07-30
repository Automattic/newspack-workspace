# AI Agent Instructions

Guidance for AI coding agents working in this repository, and the single source of truth for conventions shared across Newspack repos. Tool-specific files (`CLAUDE.md`, `.github/copilot-instructions.md`) reference this file.

## Overview

newspack-workspace is the Newspack monorepo: every product plugin, theme and shared package in one repository, plus a Docker-based local dev environment (PHP/Apache/MariaDB).

It is a **pnpm workspace** (`plugins/*`, `themes/*`, `packages/*`) with one lockfile and hoisted dependencies. Because it is one repo, cross-plugin changes are one branch and one PR.

## Workspace layout

| Path | Contents | In-container path |
| --- | --- | --- |
| `plugins/<name>/` | 12 product plugins | `/newspack-plugins/` |
| `themes/<name>/` | `newspack-theme` (classic; style variations nested inside it) and `newspack-block-theme` (FSE) | `/newspack-themes/` |
| `packages/<name>/` | `scripts`, `components`, `colors`, `icons` — note the directories are unprefixed, the npm packages are `newspack-<name>` | – |
| `repos/plugins/<name>/`, `repos/themes/<name>/` | Checkouts that live outside the monorepo | `/newspack-repos` |
| `html/` | Main WordPress site | `/var/www/html` |
| `bin/` | Shell scripts | `/var/scripts/` |
| `config/` | Apache, PHP, MySQL config | – |

Each plugin/theme directory is standalone and can be zipped and installed on its own.

**`repos/`** holds private, customer-specific or licensed checkouts (e.g. `newspack-manager`). Drop a real directory in — no registration needed, `n` commands discover them by path — then `n restart`; `bin/link-repos.sh` symlinks them into the active site. If a name also exists in `plugins/`/`themes/`, the tracked copy wins. Contents are gitignored, and a symlink *inside* `repos/` pointing outside the workspace will dangle in the container.

### Plugins

| Plugin | Purpose |
| --- | --- |
| `newspack-plugin` | **Foundation.** Setup wizard, reader activation, donations, Data Events API, webhooks, content gating, and the configuration managers other plugins consume. |
| `newspack-blocks` | Gutenberg blocks (Homepage Posts, Carousel, Author List…) |
| `newspack-popups` | Campaigns/prompts; uses reader data from newspack-plugin for targeting |
| `newspack-newsletters` | Newsletter authoring and ESP integrations (Mailchimp, ActiveCampaign…) |
| `newspack-ads` | Google Ad Manager integration and ad placements |
| `newspack-network` | Multi-site Hub/Node sync, built on the Data Events API |
| `newspack-multibranded-site` | Multiple brands within one site |
| `newspack-listings` | Directories and listings |
| `newspack-sponsors` | Sponsored content and labelling |
| `newspack-story-budget` | Newsroom editorial planning |
| `republication-tracker-tool` | Tracks republished content |
| `super-cool-ad-inserter` | Programmatic ad insertion |

**Not in this monorepo** (separate repos): `newspack-manager-admin` (hub UI on newspack.com) and `newspack-manager` (companion plugin on each managed site).

Most cross-plugin coupling runs through `newspack-plugin`. Before changing something there, find its consumers — `grep -rn "Data_Events" plugins/` and similar. Read the class you intend to call rather than trusting a remembered signature; these APIs move.

## Conventions

**File structure**

```
<plugin-name>/
├── <plugin-name>.php      # Main plugin file with header and bootstrap
├── includes/              # PHP classes: class-<name>.php
├── src/                   # JavaScript/React source
├── dist/ or build/        # Compiled assets (gitignored)
├── composer.json
├── package.json
└── phpunit.xml
```

**Naming** — PHP classes `class-newspack-<feature>.php` holding `Newspack_<Feature>`; hooks and options `newspack_<plugin>_<name>`.

**Standards** — PHP: WordPress-Extra, WordPress-Docs, WordPress-VIP-Go; short array syntax allowed, Yoda conditions not required. JS/TS: ESLint; SCSS: Stylelint; both via `newspack-scripts`. Formatting uses the `wp-prettier` fork (WordPress style needs `parenSpacing`, e.g. `( value )`), pinned workspace-wide via a pnpm override, config at `packages/scripts/config/prettier.config.js`. See [docs/code-formatting.md](docs/code-formatting.md) for the editor settings that keep IDE formatting and CI lint in agreement.

**Commits** — Conventional commits (`<type>(<scope>): <subject>`), enforced by commitlint. Subject on one line, max 72 chars, no body; `Co-Authored-By` trailers after a blank line. `feat` triggers a minor release and `fix` a patch release via semantic-release, so use those only for publisher-visible change; otherwise `chore`, `ci`, `docs`, `test`, `refactor`, `perf`, `build`, `style`, `revert`. Reference issue numbers in commits and PR descriptions.

**Never modify** changelog or `.pot` files — CI generates them.

### Pre-commit hook

A husky hook runs `lint-staged` on every `git commit` (installed by `pnpm install` at the root). It **checks** staged files and **blocks the commit** on any linter failure; it does not auto-fix. ESLint warnings are non-blocking, but Stylelint and PHPCS exit non-zero on warnings too. Only `.scss` is linted, not `.css`.

- **Auto-fix** with the package's own script: `pnpm --filter <package> run fix:js` / `format:scss` / `fix:php`.
- **Bypass** with `git commit --no-verify`, or `HUSKY=0` for one command or a whole shell. CI re-lints every PR, so skipping locally never lands unlinted code.
- **PHP scope** comes from `phpcs.xml`'s `<file>` elements, for both the hook and CI, so the hook never blocks a commit over a file CI would not lint. `bin/` is deliberately excluded: it is dev tooling, and the VIP standard's assumptions don't hold for CLI scripts.
- **Skipped during a merge.** Completing a merge stages the entire base integration, which no author wrote, so linting it would judge the merge against the base branch's lint debt. Rebase, cherry-pick and revert are not exempt.
- **Personal hooks** go in `.husky/pre-commit.local` — gitignored and per-checkout, so each worktree needs its own. Runs after the lint as a POSIX-`sh` snippet; exit non-zero to block.
- **`pnpm: command not found`** from a GUI git client means `pnpm` is not on its PATH; commit from a terminal.

Direct pushes to `main` are blocked.

## Key commands

Everything goes through the `n` script at the repository root. It is context-aware: run it from inside `plugins/<project>/`, `themes/<project>/`, `additional-sites-html/<site>/` or `manager-html/` and it targets that project or site, otherwise the main site. It works in interactive and non-interactive (CI, agent) contexts. `ncd <name>` jumps between projects (install with `n cd-install`).

```bash
n start [8.2]                 # Start containers (default PHP 8.3)
n stop | n restart

n build [name]                # Build current project, or a named one ('newspack-' optional)
n ci-build [all]              # npm ci + build
n watch [name]                # Rebuild on change

n test-php [--group X | --filter Y | --list-groups]
n test-js

n wp <command>                # WP-CLI (--allow-root added automatically)
n sh [env]                    # Shell into a container

n sites-add|sites-list|sites-drop <name>   # Extra sites at <name>.test, sharing the container
```

Run any command with `--help` for its full options.

**First-time setup**

```bash
cp default.env .env
./build-image.sh              # or ./build-image-82.sh for PHP 8.2
n start && n install
n ci-build all
n setup --yes                 # Bootstrap site with content and plugins
```

`n setup` resets the database and builds a working site: theme, plugins, posts, categories, homepage, users, menus. Add `--woocommerce` for donations/memberships/subscriptions and `--campaigns` for prompts (both off by default), or `--block-theme` for the FSE theme.

**`n wp` cannot take arguments containing spaces** — they get word-split. For SQL or `wp eval`, use `docker exec`:

```bash
docker exec newspack_dev sh -c "wp eval 'echo get_option(\"blogname\");' --allow-root"
```

The main container is `newspack_dev`; an isolated env is `newspack_env_<name>`, dashes replaced by underscores.

**`n test-php`** uses its own database (`wp_tests`, or `wp_tests_<env>` in an isolated env), separate from the site DB. All containers share one MariaDB server, so the per-env name is what stops concurrent test runs truncating each other's tables.

**`n watch`** from inside a project runs that project's incremental webpack watcher — sub-second rebuilds, and the right choice when iterating on one thing. From the root with no argument it starts a global dispatcher that spawns a watcher lazily the first time you touch a unit, so only units you actually edit get one.

**End-to-end tests** live in [`e2e/`](e2e/), a self-contained npm project deliberately outside the pnpm workspace (it needs a live site, so per-package CI must not run it). It runs nightly on TeamCity against a staging site, and can run against a local env. See [`e2e/AGENTS.md`](e2e/AGENTS.md).

## Local environment

**Services** — `wordpress` (`newspack_dev`, Apache + PHP), `db` (MariaDB 11.8.6), `mailhog` (http://localhost:8025), `adminer` (http://localhost:8088). Memcached object cache and Batcache page cache are enabled. Xdebug is on port 9003 with IDE key `DOCKERDEBUG`, mapping `/newspack-plugins/<project>` to `plugins/<project>`.

To customise the **main** stack without touching the tracked `docker-compose.yml`, create a gitignored `docker-compose.override.yml` at the root; `n start` merges it over the base stack. It does not apply to isolated envs, which layer their own generated files.

### Isolated environments

Each env is its own container, WordPress install and database, so branches run in parallel without interfering. Prefer one over the shared dev site for anything you intend to test or reproduce.

```bash
n env create <name> --worktree <repo>:<branch>   # repeatable; --domain, --up
n env up <name> [--build]     # or --all
n setup --env <name> --yes
n env down|destroy <name>
n env list [--porcelain]
n env cleanup                 # Interactive bulk cleanup
```

- Reachable at `https://<name>.test` (mkcert HTTPS) on its own loopback IP. `n start` pre-creates the aliases so envs can be created without sudo; `./bin/setup-networking.sh` removes the remaining password prompts.
- Own database (`wordpress_<name>`), own `WP_CACHE_KEY_SALT`, own `envs/<name>/html/`.
- Worktrees override individual plugins while the rest are shared. Monorepo worktrees live in `worktrees/<branch>/`; standalone `repos/` worktrees in `worktrees-repos/<name>/<branch>/`, mounted over just that path so other envs keep the base checkout. Destroying a monorepo worktree deletes its branch; a `repos/` worktree keeps it.
- All env containers share the `newspack_envs` bridge network with their domain as a DNS alias, so they can reach each other (hub/node setups).
- `n env destroy` removes the container, DB, html dir, hosts entry and worktrees.

With the `newspack` Claude Code plugin installed, `newspack:env-create`, `newspack:env-destroy` and `newspack:worktree` wrap these.

## Cross-plugin changes

One repository, so a cross-plugin change is one branch and one PR. Before changing shared code in `newspack-plugin`, find its consumers (`grep -rn "<hook or class>" plugins/`) — hooks, filters and direct calls all cross plugin boundaries. Build and test dependencies before dependents: `n build <plugin>`, then `n test-php` in each affected plugin.

## Pull requests

- **Squash merge** (`gh pr merge --squash`). The exception is branch promotions between `main`, `alpha` and `release`, which use merge commits to preserve history.
- **Never push or merge unless asked.**
- **One Copilot pass per PR**, requested when the PR opens. After addressing its feedback do not re-request it; the next review should be a human's.

With the `newspack` plugin installed: `newspack:pr-create` → `newspack:pr-feedback` → `newspack:pr-ready` → `newspack:pr-merge`, plus `newspack:pr-test` to test a PR in an isolated env. Install it with `n setup-agents`, or:

```
/plugin marketplace add Automattic/newspack-devkit
/plugin install newspack@newspack-devkit
```

## External tools

- **Linear** — use the MCP tools. Creating or updating issues and comments requires explicit user confirmation.
- **GitHub** — use the `gh` CLI.
