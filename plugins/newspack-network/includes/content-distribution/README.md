# Content Distribution

Native content distribution for Newspack Network. Lets a Hub or any networked site push posts to other sites in the network, keep them in sync on edits, and unlink/relink them as needed.

This is the successor to the [Distributor](https://github.com/10up/distributor) plugin integration in `includes/distributor-customizations/`. Both systems coexist on every site, but new work belongs here. A migrator (see below) moves existing Distributor subscriptions to this system.

## Architecture

The flow is event-driven and rides on top of `Newspack\Data_Events` and the network's webhooks. There is no direct site-to-site HTTP from this code; it dispatches events, and the network webhook layer delivers them to the target sites.

```
[origin site]                       [target site]
Outgoing_Post  ──Data_Events──▶  Incoming_Post
   payload         (webhook)         insert/update
```

### Events

Registered as Data Events on the origin site and consumed via incoming events on target sites:

- `network_post_updated` — origin post created or changed; target inserts/updates its local copy.
- `network_post_deleted` — origin post deleted; target deletes its local copy.
- `network_incoming_post_inserted` / `network_incoming_post_deleted` — emitted by the target after it processes an inbound update; used for logging and downstream reactions.
- `newspack_network_distributor_migrate_incoming_posts` — emitted by the migrator (see below) so target sites convert their Distributor incoming posts in place.

`network_post_updated` and `network_post_deleted` are bumped to priority `1` in the webhooks queue so distribution updates aren't stuck behind other events.

### Distribution lifecycle

1. A user picks target sites in the editor sidebar (or a CLI/REST call sets them). This writes `newspack_network_distributed_sites` on the origin post.
2. Any subsequent `wp_after_insert_post`, `set_object_terms`, or post-meta change on a distributed post calls `queue_post_distribution()`, which records the post ID (or a partial-update key like `post_meta`) in a static array.
3. On `shutdown`, `distribute_queued_posts()` runs each queued post through `Outgoing_Post::get_payload()` and dispatches `network_post_updated`. The payload hash is stored in `_newspack_network_payload_hash` to skip no-op redistributions.
4. Each target site receives the event, instantiates `Incoming_Post`, and inserts or updates the local copy. The local copy keeps `newspack_network_post_id`, the full `newspack_network_post_payload`, and a `newspack_network_post_unlinked` flag that lets editors override the synced content locally.

Allowed post types come from the `newspack_network_distributed_post_types` filter (defaults: `post`, `page`, plus the Newspack Listings post types).

## Distributor migrator CLI

The migrator converts existing 10up Distributor subscriptions into native distributions. It runs on the origin (Distributor "push" source) and dispatches an event so each target site rewrites its own incoming Distributor posts as native incoming posts in place — local post IDs are preserved.

While migration is running, distributed posts on target sites are temporarily locked from editing for 10 minutes (`MIGRATION_LOCK_TRANSIENT_NAME`) to avoid conflicts.

### Where to run it

Run the command **only on origin sites** — sites that pushed posts via Distributor (i.e. have `dt_subscription` posts). Target sites are converted automatically: the origin dispatches `newspack_network_distributor_migrate_incoming_posts` and each target's incoming event handler rewrites its existing Distributor copies in place.

In a typical Hub/Node setup where only the Hub pushes content, run it on the Hub. If multiple sites pushed via Distributor, run it on each of them.

### Command

```
wp newspack network distributor migrate [<post-id>] [--all] [--batch-size=<n>] [--strict] [--delete] [--dry-run]
```

Arguments:

- `<post-id>` — migrate a single Distributor-distributed post. Mutually exclusive with `--all`.
- `--all` — migrate every Distributor subscription on the site.
- `--batch-size=<n>` — number of subscriptions per batch (default `50`). Only used with `--all`.
- `--strict` — abort before migrating if any subscription fails the pre-flight check (e.g. target URL not in the network). Without this flag, unmigratable subscriptions are logged and skipped.
- `--delete` — after a fully successful `--all` run with no errors, deactivate and delete the Distributor plugin. Requires `--all`.
- `--dry-run` — log what would happen without writing.

### Usage

```bash
# Inspect first.
wp newspack network distributor migrate --all --dry-run

# Migrate everything; halt on the first unmigratable subscription.
wp newspack network distributor migrate --all --strict

# Migrate everything best-effort and remove Distributor on success.
wp newspack network distributor migrate --all --delete

# Migrate one post.
wp newspack network distributor migrate 123
```

### What it actually does

For each Distributor subscription (`dt_subscription` post):

1. Resolves the target site URL against the configured network (`Network::get_networked_urls()`); skips if the target isn't networked.
2. Calls `Outgoing_Post::set_distribution()` on the origin post for that target — this is what flips the post into a native distribution.
3. Strips `dt_subscriptions` / `dt_connection_map` entries for that subscription on the origin, then deletes the `dt_subscription` post.
4. Stores a `_newspack_network_distributor_migration_data` entry on the origin post for traceability.
5. Dispatches `newspack_network_distributor_migrate_incoming_posts` with the list of `{ site_url, post_id }` pairs. Each target site, on receiving the event, runs `Distributor_Migrator::migrate_incoming_post()` against its existing Distributor copy: it builds the native payload, copies the unlinked flag from `dt_unlinked`, calls `Incoming_Post::insert()`, and deletes the `dt_*` meta. The local post ID stays the same.

### Bypass restrictions

A post is not migratable when:

- It has internal connections (`dt_connection_map['internal']` non-empty) — Distributor's intra-multisite mode isn't supported.
- Its target URL is not part of the configured network.
- `dt_subscriptions` or required `dt_subscription_*` meta is missing.

`--strict` surfaces these as fatal; default mode logs them and continues.

## Distribute command

A separate CLI command for ad-hoc distribution of a single post (no Distributor involved):

```
wp newspack network distribute post <post-id> --sites=<urls|all> [--status_on_publish=<status>]
```

- `--sites=all` — distribute to every site in the network.
- `--sites=https://a.example,https://b.example` — comma-separated list.
- `--status_on_publish` — `draft` (default), `pending`, or `publish`. Only applies when the target inserts the post for the first time.

```bash
wp newspack network distribute post 123 --sites=all
wp newspack network distribute post 123 --sites=https://node1.example,https://node2.example --status_on_publish=publish
```
