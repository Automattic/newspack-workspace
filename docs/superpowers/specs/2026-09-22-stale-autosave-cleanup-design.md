# Stale autosave cleanup: design

Linear: NEWS-3071 (Revise revisions handling/strategy). This spec covers one piece of that ticket: cleaning up stale autosaves. The revision limit and the Content Loop category query are tracked separately (see "Out of scope").

## Problem

Opening a post in the block editor preloads `/wp/v2/<type>/<id>/autosaves?context=edit` (`wp-admin/edit-form-blocks.php:71`). The REST controller runs `the_content` on every autosave it returns (`class-wp-rest-revisions-controller.php:702`), so each autosave adds a full render of the post to the editor load.

WordPress keeps one autosave per user per post. It only cleans up an autosave when **its owner** opens the editor and the autosave is older than the post (`edit-form-blocks.php:303-310`). Autosaves from everyone else stay forever. A homepage edited by ten people carries ten autosaves, and every editor load renders all of them. On one production homepage, this made the editor take 76 seconds to open.

PR #1107 (newspack-blocks) stops the Content Loop and Carousel from running queries during those renders, which removes most of that cost. Stale autosaves still add render time for other dynamic blocks, and they accumulate without limit. This cleanup handles the housekeeping.

## How autosaves behave today

- An author editing **their own draft** autosaves into the post itself. No separate autosave exists, so this cleanup never affects that case.
- Everyone else (and anyone editing a published post) gets a single per-user autosave revision, overwritten on each autosave. Its `post_name` is `<parent_id>-autosave-v1`.
- An autosave is **stale** once the post has been saved after it. Core never offers a stale autosave back through the editor's "more recent autosave" notice, and deletes the owner's own stale autosave when they next open the editor.
- The Revisions screen lists every user's autosave, stale or not. That is the only way to recover content from a stale autosave: open it and copy content out manually.

## Rule

Delete an autosave only when all of these are true:

1. **It is stale:** the parent's `post_modified_gmt` is later than the autosave's `post_modified_gmt`.
2. **It has been stale for at least 14 days:** the post was first saved over it more than 14 days ago (see "Measuring how long it has been stale").
3. **It is not marked as a major revision.**

Fresh autosaves are never deleted, however old. Those are abandoned edits that core still offers back to their owner.

The 14-day wait is deliberately cautious. It gives collaborators two weeks to recover content from the Revisions screen after someone saves over their work. It can be shortened later.

### Measuring how long it has been stale

The obvious anchor, "parent last saved more than 14 days ago", fails on the exact pages this targets. A homepage saved every day never goes 14 days without a save, so its stale autosaves would never be deleted.

Instead, measure from the **first save after the autosave**. Each save creates a revision whose `post_date_gmt` is the parent's modified time at that save (`_wp_post_revision_data()`). An autosave has been stale for 14 days when a regular (non-autosave) revision of the same parent exists with:

- `post_date_gmt` later than the autosave's `post_modified_gmt`, and
- `post_date_gmt` earlier than the cutoff (now minus 14 days).

**Fallback:** revisions may be disabled for the post type, or the overtaking revision may have been pruned. For those cases, also treat the autosave as eligible when the parent itself was last modified before the cutoff. This rule only ever waits longer than 14 days, never less.

### Query

One query selects a batch of eligible autosave IDs:

```sql
SELECT a.ID
FROM   {posts} a
JOIN   {posts} p ON p.ID = a.post_parent
WHERE  a.post_type = 'revision'
  AND  a.post_name = CONCAT( p.ID, '-autosave-v1' )
  AND  a.post_modified_gmt < p.post_modified_gmt           -- stale
  AND  (
         p.post_modified_gmt < %cutoff                     -- fallback
         OR EXISTS (
           SELECT 1 FROM {posts} r
           WHERE  r.post_parent = p.ID
             AND  r.post_type = 'revision'
             AND  r.post_name NOT LIKE '%-autosave-v%'
             AND  r.post_date_gmt > a.post_modified_gmt
             AND  r.post_date_gmt < %cutoff
         )
       )
  AND  NOT EXISTS (                                        -- not major
         SELECT 1 FROM {postmeta} m
         WHERE  m.post_id = p.ID
           AND  m.meta_key = '_major_revision'
           AND  m.meta_value = a.ID
       )
ORDER  BY a.ID
LIMIT  %batch
```

Excluding major revisions in SQL, rather than in PHP after the fact, stops a batch full of major autosaves from blocking every later run. Everything is compared in GMT, so the site timezone doesn't matter.

Each ID is deleted through `wp_delete_post_revision()`, so core and plugin hooks (`delete_post`, `deleted_post`, meta cleanup, object cache) run as usual.

## Components

All in `plugins/newspack-plugin/includes/revisions-control/`.

### `class-autosave-cleanup.php` — `Newspack\Autosave_Cleanup`

- Static `init()` pattern, loaded from `class-revisions-control.php` beside `class-major-revisions.php`.
- `CRON_HOOK = 'newspack_autosave_cleanup'`, scheduled daily. Honours `NEWSPACK_CRON_DISABLE` the same way `OAuth_Transients` does (`includes/oauth/class-oauth-transients.php:49`).
- `get_days()` returns the wait: 14 by default, overridable with a documented constant `NEWSPACK_AUTOSAVE_CLEANUP_DAYS` (a positive integer, docblock in the same format as `NEWSPACK_LIMIT_REVISIONS_NUMBER`).
- `get_eligible_ids( $days, $limit, $post_id = null )` runs the query above, optionally scoped to one parent.
- `prune( $days, $limit, $post_id = null, $dry_run = false )` deletes the batch and returns the IDs it handled.
- The cron callback deletes up to 500 autosaves per run, in batches of 100. A site with a large backlog clears it over a few days rather than in one long request.
- Logs a count per run via `Logger::log()` under a `NEWSPACK-AUTOSAVE-CLEANUP` header.

The cleanup runs regardless of whether the revision limit (`newspack_revisions_control`) is on. The two are independent.

### WP-CLI: `wp newspack autosaves prune`

Registered in `includes/cli/class-initializer.php`.

```
wp newspack autosaves prune [--dry-run] [--post=<id>] [--older-than=<days>]
```

- `--dry-run` lists what would be deleted (autosave ID, parent ID, author, autosave date) and deletes nothing.
- `--post=<id>` limits the run to one post. Support can clear one heavy page after checking with the publisher.
- `--older-than=<days>` overrides the wait for this run. `0` removes every stale autosave on the targeted posts. Staleness and the major-revision check still apply, so fresh autosaves are never deleted from the CLI either.
- Processes every eligible row in batches of 100 and prints a summary.

### Documentation

Add a short "Autosave cleanup" section to `includes/revisions-control/README.md` covering the rule, the 14-day default, the constant, `NEWSPACK_CRON_DISABLE` and the CLI.

## Tests

New `tests/unit-tests/autosave-cleanup.php` (`WP_UnitTestCase`). Build fixtures with `_wp_put_post_revision( $post, true )` for autosaves, then set `post_modified_gmt` and `post_date_gmt` directly with `$wpdb->update()`.

| Case | Expected |
|---|---|
| Autosave overtaken by a save more than 14 days ago | Deleted |
| Autosave overtaken by a save within the last 14 days | Kept |
| Homepage saved daily; autosave overtaken more than 14 days ago | Deleted (the anchor does not reset on later saves) |
| Revisions disabled, parent unmodified for more than 14 days, autosave stale | Deleted (fallback) |
| Revisions disabled, parent modified yesterday, autosave stale | Kept |
| Fresh autosave (newer than the parent), 60 days old | Kept |
| Stale autosave marked major | Kept, and does not block later batches |
| Regular (non-autosave) revisions | Never deleted |
| `--dry-run` / `$dry_run = true` | Nothing deleted, IDs returned |
| `--post=<id>` | Only that post's autosaves considered |
| `NEWSPACK_AUTOSAVE_CLEANUP_DAYS` set | Wait follows the constant |
| Cron in `NEWSPACK_CRON_DISABLE` | Not scheduled; cleared if already scheduled |

## Risks

- **Loss of manual recovery.** After 14 days of being stale, a collaborator can no longer copy content out of an autosave on the Revisions screen. Core already discards the owner's own stale autosave when they next open the editor, so this only removes recovery that nobody had used for two weeks. Jetpack backups remain a support-level fallback.
- **Plugins and background processes.** Code that calls `wp_update_post()` (Quick Edit, bulk edit, Newspack Network sync, imports) moves the parent's modified time and can make an autosave stale without a person saving. The 14-day wait covers this. Core treats these autosaves the same way when their owner opens the editor.
- **Someone still editing.** A user mid-edit whose autosave is overtaken by a colleague's save cannot be affected. Nothing is deleted until 14 days later, and their next autosave replaces the stale one anyway.
- **Query cost.** The query filters on `post_type = 'revision'` (indexed) and joins by primary key. The `EXISTS` subquery uses the `post_parent` index. It runs once a day with a `LIMIT`, so it adds no cost to page loads. Verify with `EXPLAIN` on a site with a large `wp_posts` table before release.

## Out of scope

- **Revision limit (parked).** Turning the Newspack limit on by default (it overrides Atomic's `WP_POST_REVISIONS` of 500), a batched revision cleanup, publisher communication so revisions can be marked major, and the `min_age` timezone bug in `class-revisions-control.php`.
- **Content Loop category query (parked, needs a ticket).** The include-plus-exclude category query that takes about 1.9 seconds and also hits front-end homepage renders. Draft a separate Linear ticket for review.
- **Content Loop render during REST revision requests.** Handled by PR #1107.
- **Auto-draft cleanup.** Core already deletes `auto-draft` posts older than 7 days.

## Rollout

Ships as a normal `fix` in newspack-plugin. On first run after release, each site deletes up to 500 eligible autosaves per day until any backlog clears. Autosaves stale for less than 14 days are never touched, so no publisher sees an immediate change beyond faster editor loads on pages that had piled up autosaves.
