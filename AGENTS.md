# AI Agent Instructions

This file provides guidance to AI coding agents working with code in this repository. It is the single source of truth for shared conventions across all Newspack repos. Tool-specific files (`CLAUDE.md`, `.github/copilot-instructions.md`, etc.) reference this file.

## Overview

newspack-workspace is a Docker-based local development environment for Newspack WordPress plugins and themes. It provides containerized PHP/Apache/MySQL with all dependencies needed to develop, build, and test Newspack projects.

**This repository serves as a monorepo-like workspace** where all Newspack plugins and themes are cloned into the `repos/` directory. Agents can make changes across multiple plugins from this single location.

## Working Across Multiple Repositories

### Repository Locations

All Newspack repositories are cloned to `./repos/<project-name>/`. Each is an independent Git repository hosted at `github.com/Automattic/<project-name>`.

### Plugins and Themes

The Newspack product consists of these interconnected plugins and themes:

**Core Plugin:**
- `newspack-plugin` - The main Newspack plugin. Provides the setup wizard, reader management, donations, data events API, and integrations with other plugins. Most other plugins depend on utilities from this plugin.

**Content & Publishing:**
- `newspack-blocks` - Custom Gutenberg blocks for news sites (Homepage Posts, Carousel, Author List, etc.)
- `newspack-listings` - Directory and listing functionality for events, places, and marketplaces
- `newspack-sponsors` - Sponsored content management and labeling

**Reader Revenue:**
- `newspack-popups` - Campaigns/prompts system for reader engagement (popups, inline prompts, overlays)

**Newsletters:**
- `newspack-newsletters` - Newsletter authoring and sending via ESP integrations (Mailchimp, ActiveCampaign, Constant Contact, etc.)

**Advertising:**
- `newspack-ads` - Google Ad Manager integration and ad placement management
- `super-cool-ad-inserter-plugin` - Programmatic ad insertion into content

**Multi-site & Network:**
- `newspack-network` - Synchronization system for multi-site Newspack networks (Hub/Node architecture)
- `newspack-multibranded-site` - Support for multiple brands within a single WordPress site

**Manager (SaaS):**
- `newspack-manager` - Server-side component for Newspack-as-a-service
- `newspack-manager-client` - Client plugin that connects sites to Newspack Manager

**Syndication:**
- `republication-tracker-tool` - Tracks content republication across sites

**Themes:**
- `newspack-theme` - Classic theme and base for style variations
- `newspack-joseph`, `newspack-katharine`, `newspack-nelson`, `newspack-sacha`, `newspack-scott` - Theme variations built on `newspack-theme`
- `newspack-block-theme` - FSE block theme for Newspack sites

### Plugin Relationships

Understanding how plugins interact is crucial for cross-repo changes:

- **newspack-plugin** is the foundation. It provides:
  - Data Events API (used by newspack-network, newspack-newsletters)
  - Reader data management (used by newspack-popups, newspack-newsletters)
  - Webhooks system (used by newspack-network)
  - Configuration managers for other plugins

- **newspack-popups** uses reader data from newspack-plugin to target campaigns

- **newspack-newsletters** integrates with newspack-popups for subscription prompts

- **newspack-network** uses the Data Events API from newspack-plugin to sync data across sites

- **newspack-blocks** is used across all Newspack sites for content presentation

### Common Patterns Across Repos

**File Structure:**
```
<plugin-name>/
├── <plugin-name>.php      # Main plugin file with header and bootstrap
├── includes/              # PHP classes
│   ├── class-<name>.php   # Main plugin class
│   └── class-*.php        # Feature classes
├── src/                   # JavaScript/React source
├── dist/ or build/        # Compiled assets (gitignored)
├── composer.json          # PHP dependencies
├── package.json           # JS dependencies
└── phpunit.xml            # Test configuration
```

**Naming Conventions:**
- PHP classes: `class-newspack-<feature>.php` with `Newspack_<Feature>` class name
- Hooks: `newspack_<plugin>_<action>` for actions, same for filters
- Options: `newspack_<plugin>_<option_name>`

**Coding Standards:**
- **PHP**: WordPress-Extra, WordPress-Docs, WordPress-VIP-Go standards. Short array syntax `[]` is allowed. Yoda conditions not required.
- **JavaScript/TypeScript**: ESLint via `newspack-scripts`
- **SCSS**: Stylelint via `newspack-scripts`
- **Commits**: Conventional commits (`<type>(<scope>): <subject>`) enforced via commitlint. Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`. Releases are automated via semantic-release: `feat` triggers a minor release, `fix` triggers a patch release.
- Pre-commit hooks run lint-staged automatically (requires `composer install` in each repo). Direct pushes to `trunk` are blocked.
- Reference issue numbers in commits and PR descriptions.
- Do not modify changelog files or `.pot` translation files. These are auto-generated by CI workflows.

## Key Commands

All commands use the `n` script from the repository root. The `n` script is context-aware: it detects your current working directory and targets the appropriate project/container automatically. It works in both interactive terminals and non-interactive contexts (CI, AI agents).

### Container Management
```bash
n start           # Start containers (PHP 8.3)
n start 8.2       # Start with PHP 8.2
n stop            # Stop containers
n restart         # Stop and start
```

### First-Time Setup
```bash
cp default.env .env           # Create local config
./build-image.sh              # Build Docker image (PHP 8.3)
./build-image-82.sh           # Build PHP 8.2 image
./clone-repos.sh              # Clone all Newspack repos to ./repos/
n start                       # Launch containers
n install                     # Install WordPress
n ci-build all                # Build all projects
```

### Building Projects
```bash
n build                       # Build current project (from within repo folder)
n build newspack-plugin       # Build specific project
n build newsletters           # 'newspack-' prefix can be omitted
n ci-build                    # npm ci + build for current project
n ci-build all                # Build all projects
```

### Testing
```bash
n test-php                          # Run all PHPUnit tests (from within repo folder)
n test-php --group byline-block     # Run tests by group
n test-php --filter test_name       # Run a specific test method
n test-php --list-groups            # List available test groups
n test-js                           # Run JS tests
```

### Development
```bash
n watch                       # Watch mode for current project
n composer <cmd>              # Run composer in current project
n npm <cmd>                   # Run npm in current project
```

### WordPress CLI
```bash
n wp <command>                # Run WP-CLI command (--allow-root is added automatically)
```

**Quoting limitation**: `n wp` does not support arguments with spaces (SQL queries, `wp eval` code, etc.) because they get word-split. For these, use `docker exec` directly:
```bash
docker exec newspack_dev sh -c "wp db query 'SELECT * FROM wp_options WHERE option_name=\"siteurl\";' --allow-root"
docker exec newspack_dev sh -c "wp eval 'echo get_option(\"blogname\");' --allow-root"
```
The main container is `newspack_dev`. For isolated environments, the container name is `newspack_env_<name>` where `<name>` matches what was passed to `n env create <name>`, with dashes replaced by underscores (e.g., `n env create my-feature` creates container `newspack_env_my_feature`).

### Multi-Site
```bash
n sites-add <name>            # Create additional site at name.local
n sites-list                  # List additional sites
n sites-drop <name>           # Remove site
```

### Working Across Repos
```bash
n pull                        # Git pull all repos
n branch <name>               # Switch all repos to branch
n trunk                       # Switch all repos to trunk
n alpha                       # Switch all repos to alpha
n release                     # Switch all repos to release
```

## Architecture

### Directory Structure
- `repos/` - Cloned Newspack repositories (plugins + themes), mounted at `/newspack-repos/` in container
- `html/` - Main WordPress site, mounted at `/var/www/html`
- `additional-sites-html/` - Additional WordPress sites
- `manager-html/` - Newspack Manager site
- `bin/` - Shell scripts mounted at `/var/scripts/` in container
- `config/` - Apache, PHP, MySQL configuration

### Docker Services
- `wordpress` (container: `newspack_dev`) - Apache + PHP + WordPress
- `db` - MariaDB 10.8.2
- `mailhog` - Email capture at http://localhost:8025
- `adminer` - Database UI at http://localhost:8088

### Context-Aware Commands
The `n` script detects your current working directory:
- From `repos/<project>/` - commands target that project
- From `additional-sites-html/<site>/` - commands target that site
- From `manager-html/` - commands target the manager site
- Otherwise - commands target the main site

Use `ncd <name>` (install with `n cd-install`) for quick navigation between projects.

### Caching
- Memcached enabled via `html/wp-content/object-cache.php`
- Batcache for page caching via `advanced-cache.php`

### Xdebug
Configured on port 9003 with IDE key `DOCKERDEBUG`. Path mapping: `/newspack-repos/<project>` maps to local `repos/<project>`.

## Isolated Environments for Parallel Development

Use the `newspack` plugin skills to manage worktrees and isolated Docker environments:
- `newspack:worktree` — Create or remove git worktrees for branch isolation
- `newspack:env-create` — Create worktrees + isolated Docker environment on a separate port
- `newspack:env-destroy` — Destroy environment and clean up worktrees

If the `newspack` plugin is not available, use the `n worktree` and `n env` commands directly. Run `n worktree --help` and `n env --help` for usage.

## Cross-Repository Workflow

When making changes that span multiple plugins:

### 1. Understand the Change Scope
Before making changes, identify which repos are affected:
- Check for hooks/filters that connect plugins (grep for `do_action`, `apply_filters`, `add_action`, `add_filter`)
- Look for direct function calls between plugins
- Check if changing an API that other plugins consume

### 2. Make Changes in Dependency Order
If Plugin A depends on Plugin B:
1. Make changes to Plugin B first
2. Build Plugin B: `n build <plugin-b>`
3. Test Plugin B in isolation
4. Make changes to Plugin A
5. Build and test Plugin A

### 3. Testing Cross-Plugin Changes
```bash
# Rebuild affected plugins
n build newspack-plugin
n build newspack-popups

# Run PHP tests for each
cd repos/newspack-plugin && n test-php
cd repos/newspack-popups && n test-php
```

### 4. Git Workflow for Multi-Repo Changes
Each repo in `repos/` is independent. For related changes:
1. Create branches with the same name across repos for easier tracking
2. Commit to each repo separately
3. Reference related PRs in commit messages/PR descriptions

Example:
```bash
# In each affected repo
cd repos/newspack-plugin
git checkout -b feature/my-feature
# ... make changes, commit ...

cd repos/newspack-newsletters
git checkout -b feature/my-feature
# ... make changes, commit ...
```

### 5. Finding Code Across Repos
```bash
# Search all repos for a hook
grep -r "newspack_reader_logged_in" repos/

# Find where a function is defined
grep -rn "function get_reader_data" repos/

# Find all usages of a class
grep -rn "Newspack_Popups" repos/
```

## Common Integration Points

### Data Events API (newspack-plugin)
Used for async event processing:
```php
// Registering an event handler
Newspack\Data_Events::register_handler('reader_logged_in', 'my_handler');

// Dispatching an event
Newspack\Data_Events::dispatch('reader_logged_in', $data);
```

### Reader Data (newspack-plugin)
Central reader/user management:
```php
// Get current reader
$reader = Newspack\Reader_Activation::get_current_reader();

// Check reader status
Newspack\Reader_Activation::is_reader_logged_in();
```

### Webhooks (newspack-plugin)
For external integrations:
```php
Newspack\Webhooks::send('endpoint_id', $payload);
```

### Configuration Managers
newspack-plugin provides configuration managers for other plugins:
- `Newspack_Popups_Configuration_Manager`
- `Newspack_Ads_Configuration_Manager`
- `Newspack_Theme_Configuration_Manager`

## Git & Commit Rules

- **Merge strategy**: Always use **squash merge** (`gh pr merge --squash`) when merging PRs. The only exceptions are branch promotions (`trunk` to `alpha`, `alpha` to `release`, `release` to `trunk`, `release` to `alpha`), which use merge commits to preserve history.
- **Commit messages**: Single line, max 72 characters. Conventional commit format: `<type>(<scope>): <subject>`. No body, no `Co-Authored-By`, no extra attributes.
- **Never push automatically**. Always ask for confirmation before pushing to remote.

## Claude Code Plugin

The `newspack` plugin provides Newspack-specific skills for PR workflows and development tasks. See the [newspack-devkit README](https://github.com/Automattic/newspack-devkit) for the full list of available skills.

If the plugin is not installed, run `n setup-agents` to install all recommended plugins, or add it manually:

```
/plugin marketplace add Automattic/newspack-devkit
/plugin install newspack@newspack-devkit
```

## Pull Requests

Use the `newspack` plugin skills for the full PR lifecycle:
1. `newspack:pr-create` — Create draft PR and request Copilot review
2. `newspack:pr-feedback` — Address review comments and resolve threads
3. `newspack:pr-ready` — Mark ready for human review and apply label
4. `newspack:pr-merge` — Merge after approval and checks pass
5. `newspack:pr-test` — Test a PR in an isolated environment with automated checks and code review

## External Tools

- **Linear**: Use MCP tools for Linear operations when available. Write operations (creating or updating issues, comments, etc.) require explicit user confirmation.
- **GitHub**: Always use `gh` CLI for GitHub operations (PRs, issues, checks, releases, etc.).

## GitHub Information

All repositories are under the Automattic GitHub organization:
- URL pattern: `https://github.com/Automattic/<repo-name>`
- Default branch: `trunk` (some older repos may use `master`)
- PR target: Usually `trunk` branch
