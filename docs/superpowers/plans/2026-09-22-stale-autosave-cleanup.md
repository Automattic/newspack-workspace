# Stale Autosave Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete autosaves in newspack-plugin that have been stale (overtaken by a later save) for at least 14 days, via a daily cron and a WP-CLI command.

**Architecture:** A new `Newspack\Autosave_Cleanup` class in `includes/revisions-control/` selects eligible autosave IDs with one SQL query, deletes them through `wp_delete_post_revision()`, and runs daily in capped batches. A `Newspack\CLI\Autosaves` class exposes the same logic as `wp newspack autosaves prune`, with a dry run.

**Tech Stack:** PHP 8.3, WordPress 7.1 (cron, `$wpdb`, revisions API), WP-CLI, PHPUnit via `n test-php`.

**Spec:** `docs/superpowers/specs/2026-09-22-stale-autosave-cleanup-design.md`

## Global Constraints

- Default wait: **14 days**. Overridable only by the constant `NEWSPACK_AUTOSAVE_CLEANUP_DAYS` (positive integer).
- Cron hook: `newspack_autosave_cleanup`, schedule `daily`, disabled when listed in `NEWSPACK_CRON_DISABLE`.
- Cron deletes at most **500** autosaves per run, in batches of **100**.
- An autosave is eligible only if it is stale, has been stale ≥ the wait (anchored to the first later save, with the parent-modified fallback), and is not in the parent's `_major_revision` meta.
- Fresh autosaves are never deleted, including from the CLI with `--older-than=0`.
- All date comparisons use `*_gmt` columns.
- Logger header: `NEWSPACK-AUTOSAVE-CLEANUP`.
- Commit messages: conventional commits, `fix(revisions): …`, subject ≤ 72 chars, attribution trailer after a blank line. **Do not commit, push or open a PR without Laurel's explicit approval.**
- Lint PHP from the workspace root with `composer phpcs -- <path>`; never use the package's own `lint:php`/`fix:php`.
- Don't hand-edit changelogs or translation files.

## Review Focus

1. **Dry run with more than one batch of eligible autosaves.** The CLI must terminate. Dry run deletes nothing, so re-querying from the start would loop forever. Pagination by `after_id` prevents this (test in Task 3).
2. **Deletion blocked by another filter.** `Revisions_Control::pre_delete_revision()` (when a site has the revision limit on with a long `min_age`) or third-party code can veto `pre_delete_post`. The cron must walk past vetoed IDs, not stop or retry them forever (test in Task 2).
3. **A batch full of major autosaves.** They must be excluded in SQL so they never fill a batch and block later eligible rows (test in Task 1).
4. **A homepage saved every day.** Later saves must not reset the 14-day clock (test in Task 1).
5. **Passing a non-autosave ID to the delete method.** A regular revision or a normal post must never be deleted, even if a caller passes its ID (test in Task 1).

## Deviations from the spec

- `get_eligible_ids()` gains an `$after_id` argument for keyset pagination (Review Focus 1–2).
- `run_cron()` takes an optional `$max` argument so the per-run cap can be tested without creating 500 posts.
- Tests can't cover `NEWSPACK_AUTOSAVE_CLEANUP_DAYS` or `NEWSPACK_CRON_DISABLE`: a PHP constant can't be undefined within one PHPUnit process. Both are verified manually in Task 4.

## File Structure

| File | Responsibility |
|---|---|
| Create `plugins/newspack-plugin/includes/revisions-control/class-autosave-cleanup.php` | Eligibility query, safe deletion, cron scheduling and the cron runner |
| Modify `plugins/newspack-plugin/includes/revisions-control/class-revisions-control.php:13` | `require_once` the new class beside `class-major-revisions.php` |
| Create `plugins/newspack-plugin/includes/cli/class-autosaves.php` | `wp newspack autosaves prune` command |
| Modify `plugins/newspack-plugin/includes/cli/class-initializer.php` | Include and register the command |
| Modify `plugins/newspack-plugin/includes/revisions-control/README.md` | "Autosave cleanup" section |
| Create `plugins/newspack-plugin/tests/unit-tests/autosave-cleanup.php` | Tests for the class and cron |
| Create `plugins/newspack-plugin/tests/unit-tests/autosave-cleanup-cli.php` | Tests for the CLI command |
| Create `plugins/newspack-plugin/tests/unit-tests/traits/trait-autosave-fixtures.php` | Shared fixture helpers for both test files |

All `n` commands below run from `plugins/newspack-plugin/`. `n composer` and `n test-php` resolve the project from the current directory.

## Before starting

- [ ] Confirm with Laurel which branch to work on, e.g. `news-3071-stale-autosave-cleanup` off `main`. Don't create it or commit to `main` without approval.
- [ ] `n start` if the containers aren't running.

---

### Task 1: Eligibility query and safe deletion

**Files:**
- Create: `plugins/newspack-plugin/includes/revisions-control/class-autosave-cleanup.php`
- Modify: `plugins/newspack-plugin/includes/revisions-control/class-revisions-control.php:13`
- Test: `plugins/newspack-plugin/tests/unit-tests/autosave-cleanup.php`
- Test helper: `plugins/newspack-plugin/tests/unit-tests/traits/trait-autosave-fixtures.php`

**Interfaces:**
- Produces:
  - Test trait `Autosave_Fixtures` with `create_post( int $days ): int`, `create_revision( int $post_id, int $days, bool $autosave = false ): int`, `create_eligible_autosave(): array{0:int,1:int}` (post ID, autosave ID)
  - `Autosave_Cleanup::DEFAULT_DAYS` (int, 14), `::BATCH_SIZE` (int, 100)
  - `Autosave_Cleanup::get_days(): int`
  - `Autosave_Cleanup::get_eligible_ids( int $days, int $limit, int $post_id = 0, int $after_id = 0 ): int[]`, ascending by ID
  - `Autosave_Cleanup::delete_autosaves( int[] $ids ): int[]`, returning the IDs actually deleted

- [ ] **Step 1: Write the failing tests**

Create `tests/unit-tests/traits/trait-autosave-fixtures.php` (shared with the CLI tests in Task 3, following the `traits/` convention used elsewhere in `tests/unit-tests/`):

```php
<?php
/**
 * Fixture helpers for autosave cleanup tests.
 *
 * @package Newspack\Tests
 */

/**
 * Creates posts, autosaves and revisions with controlled dates.
 */
trait Autosave_Fixtures {

	/**
	 * GMT datetime a number of days in the past.
	 *
	 * @param int $days Days ago.
	 * @return string
	 */
	protected function days_ago( $days ) {
		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Overwrite date columns directly, since WordPress sets them to now on insert.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Columns to set.
	 */
	protected function set_dates( $post_id, $fields ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, $fields, [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $post_id );
	}

	/**
	 * Create a post last saved $days ago, with no revisions.
	 *
	 * @param int $days Days since the post was last saved.
	 * @return int Post ID.
	 */
	protected function create_post( $days ) {
		$post_id = self::factory()->post->create();
		foreach ( wp_get_post_revisions( $post_id ) as $revision ) {
			wp_delete_post_revision( $revision );
		}
		$date = $this->days_ago( $days );
		$this->set_dates( $post_id, [ 'post_modified_gmt' => $date, 'post_modified' => $date ] );
		return $post_id;
	}

	/**
	 * Create an autosave or regular revision saved $days ago.
	 *
	 * @param int  $post_id  Parent post ID.
	 * @param int  $days     Days since it was saved.
	 * @param bool $autosave Whether to create an autosave.
	 * @return int Revision ID.
	 */
	protected function create_revision( $post_id, $days, $autosave = false ) {
		$revision_id = _wp_put_post_revision( get_post( $post_id ), $autosave );
		$date        = $this->days_ago( $days );
		$this->set_dates(
			$revision_id,
			[
				'post_date_gmt'     => $date,
				'post_date'         => $date,
				'post_modified_gmt' => $date,
				'post_modified'     => $date,
			]
		);
		return $revision_id;
	}

	/**
	 * Create a post whose autosave has been stale for 20 days.
	 *
	 * @return array [ post ID, autosave ID ].
	 */
	protected function create_eligible_autosave() {
		$post_id     = $this->create_post( 20 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->create_revision( $post_id, 20 );
		return [ $post_id, $autosave_id ];
	}
}
```

Then create `tests/unit-tests/autosave-cleanup.php`:

```php
<?php
/**
 * Tests the stale autosave cleanup.
 *
 * @package Newspack\Tests
 */

use Newspack\Autosave_Cleanup;

require_once __DIR__ . '/traits/trait-autosave-fixtures.php';

/**
 * Test Autosave Cleanup functionality.
 */
class Newspack_Test_Autosave_Cleanup extends WP_UnitTestCase {
	use Autosave_Fixtures;

	/**
	 * Test the default wait.
	 */
	public function test_get_days_defaults_to_14() {
		$this->assertSame( 14, Autosave_Cleanup::get_days() );
	}

	/**
	 * An autosave overtaken by a save 20 days ago is eligible.
	 */
	public function test_autosave_stale_longer_than_wait_is_eligible() {
		[ , $autosave_id ] = $this->create_eligible_autosave();
		$this->assertContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 14, 100 ) );
	}

	/**
	 * An autosave overtaken by a save 5 days ago is kept.
	 */
	public function test_autosave_recently_overtaken_is_not_eligible() {
		$post_id     = $this->create_post( 5 );
		$autosave_id = $this->create_revision( $post_id, 10, true );
		$this->create_revision( $post_id, 5 );
		$this->assertNotContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 14, 100 ) );
	}

	/**
	 * Saving a page daily does not reset the clock once it was overtaken 20 days ago.
	 */
	public function test_daily_saves_do_not_reset_the_wait() {
		$post_id     = $this->create_post( 1 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		foreach ( [ 20, 10, 5, 1 ] as $days ) {
			$this->create_revision( $post_id, $days );
		}
		$this->assertContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 14, 100 ) );
	}

	/**
	 * Without revisions, a stale autosave on a post untouched for 20 days is eligible.
	 */
	public function test_fallback_without_revisions_is_eligible() {
		$post_id     = $this->create_post( 20 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->assertContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 14, 100 ) );
	}

	/**
	 * Without revisions, a stale autosave on a post saved yesterday is kept.
	 */
	public function test_fallback_without_revisions_recent_save_is_not_eligible() {
		$post_id     = $this->create_post( 1 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->assertNotContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 14, 100 ) );
	}

	/**
	 * A fresh autosave (newer than the post) is never eligible, however old.
	 */
	public function test_fresh_autosave_is_never_eligible() {
		$post_id     = $this->create_post( 60 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->assertNotContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 0, 100 ) );
	}

	/**
	 * A major autosave is excluded in SQL, so it can't fill a batch.
	 */
	public function test_major_autosave_is_excluded_and_does_not_block_batch() {
		[ $major_post, $major_autosave ] = $this->create_eligible_autosave();
		add_post_meta( $major_post, '_major_revision', $major_autosave );
		[ , $eligible_autosave ] = $this->create_eligible_autosave();

		$this->assertSame( [ $eligible_autosave ], Autosave_Cleanup::get_eligible_ids( 14, 1 ) );
	}

	/**
	 * Regular revisions are never returned.
	 */
	public function test_regular_revisions_are_never_eligible() {
		$post_id     = $this->create_post( 1 );
		$revision_id = $this->create_revision( $post_id, 60 );
		$this->assertNotContains( $revision_id, Autosave_Cleanup::get_eligible_ids( 0, 100 ) );
	}

	/**
	 * The post argument scopes the query to one parent.
	 */
	public function test_post_id_scopes_results() {
		[ $post_a, $autosave_a ] = $this->create_eligible_autosave();
		$this->create_eligible_autosave();
		$this->assertSame( [ $autosave_a ], Autosave_Cleanup::get_eligible_ids( 14, 100, $post_a ) );
	}

	/**
	 * The after_id argument pages through results.
	 */
	public function test_after_id_pages_results() {
		[ , $first ]  = $this->create_eligible_autosave();
		[ , $second ] = $this->create_eligible_autosave();
		$this->assertSame( [ $second ], Autosave_Cleanup::get_eligible_ids( 14, 100, 0, $first ) );
	}

	/**
	 * Only autosaves are deleted, whatever IDs are passed.
	 */
	public function test_delete_autosaves_only_deletes_autosaves() {
		[ $post_id, $autosave_id ] = $this->create_eligible_autosave();
		$revision_id               = $this->create_revision( $post_id, 40 );

		$deleted = Autosave_Cleanup::delete_autosaves( [ $autosave_id, $revision_id, $post_id ] );

		$this->assertSame( [ $autosave_id ], $deleted );
		$this->assertNull( get_post( $autosave_id ) );
		$this->assertNotNull( get_post( $revision_id ) );
		$this->assertNotNull( get_post( $post_id ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `n test-php --filter Newspack_Test_Autosave_Cleanup`
Expected: errors, `Class "Newspack\Autosave_Cleanup" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `includes/revisions-control/class-autosave-cleanup.php`:

```php
<?php
/**
 * Deletes autosaves that were overtaken by a later save long enough ago that
 * nobody is likely to recover them. The editor never offers a stale autosave
 * back, but still renders every one on load.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Autosave Cleanup class.
 */
class Autosave_Cleanup {

	const DEFAULT_DAYS = 14;
	const BATCH_SIZE   = 100;

	/**
	 * Number of days an autosave must have been stale before it's deleted.
	 *
	 * @return int
	 */
	public static function get_days() {
		/**
		 * Days an autosave must have been stale (overtaken by a later save)
		 * before the daily cleanup deletes it.
		 *
		 * @constant NEWSPACK_AUTOSAVE_CLEANUP_DAYS
		 * @type     int
		 * @default  14
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_AUTOSAVE_CLEANUP_DAYS', 30 );
		 */
		if ( defined( 'NEWSPACK_AUTOSAVE_CLEANUP_DAYS' ) && is_int( NEWSPACK_AUTOSAVE_CLEANUP_DAYS ) && NEWSPACK_AUTOSAVE_CLEANUP_DAYS > 0 ) {
			return NEWSPACK_AUTOSAVE_CLEANUP_DAYS;
		}
		return self::DEFAULT_DAYS;
	}

	/**
	 * Get IDs of autosaves that have been stale for at least $days.
	 *
	 * Stale means the parent was saved after the autosave. The wait starts at
	 * the first regular revision after the autosave, so later saves don't
	 * reset it; the parent's modified date is the fallback when revisions are
	 * disabled or pruned. Autosaves marked as major are never returned.
	 *
	 * @param int $days     Minimum days stale.
	 * @param int $limit    Maximum IDs to return.
	 * @param int $post_id  Limit to one parent post, 0 for all.
	 * @param int $after_id Only return IDs greater than this, for paging.
	 * @return int[] Autosave IDs, ascending.
	 */
	public static function get_eligible_ids( $days, $limit, $post_id = 0, $after_id = 0 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT a.ID
				FROM {$wpdb->posts} a
				JOIN {$wpdb->posts} p ON p.ID = a.post_parent
				WHERE a.post_type = 'revision'
					AND a.post_name = CONCAT( p.ID, '-autosave-v1' )
					AND a.post_modified_gmt < p.post_modified_gmt
					AND (
						p.post_modified_gmt < %s
						OR EXISTS (
							SELECT 1 FROM {$wpdb->posts} r
							WHERE r.post_parent = p.ID
								AND r.post_type = 'revision'
								AND r.post_name NOT LIKE %s
								AND r.post_date_gmt > a.post_modified_gmt
								AND r.post_date_gmt < %s
						)
					)
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} m
						WHERE m.post_id = p.ID
							AND m.meta_key = '_major_revision'
							AND m.meta_value = CAST( a.ID AS CHAR )
					)
					AND ( 0 = %d OR p.ID = %d )
					AND a.ID > %d
				ORDER BY a.ID
				LIMIT %d",
				$cutoff,
				'%' . $wpdb->esc_like( '-autosave-v' ) . '%',
				$cutoff,
				$post_id,
				$post_id,
				$after_id,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Delete autosaves by ID. Anything that isn't an autosave is skipped.
	 *
	 * @param int[] $ids Autosave IDs.
	 * @return int[] IDs that were deleted.
	 */
	public static function delete_autosaves( $ids ) {
		$deleted = [];
		foreach ( $ids as $id ) {
			if ( ! wp_is_post_autosave( $id ) ) {
				continue;
			}
			if ( wp_delete_post_revision( $id ) ) {
				$deleted[] = (int) $id;
			}
		}
		return $deleted;
	}
}
```

Then in `includes/revisions-control/class-revisions-control.php`, after line 13 (`require_once 'class-major-revisions.php';`), add:

```php
require_once 'class-autosave-cleanup.php';
```

Regenerate the classmap:

Run: `n composer dump-autoload`

- [ ] **Step 4: Run the tests to verify they pass**

Run: `n test-php --filter Newspack_Test_Autosave_Cleanup`
Expected: PASS, 12 tests.

- [ ] **Step 5: Lint**

Run from the workspace root: `composer phpcs -- plugins/newspack-plugin/includes/revisions-control plugins/newspack-plugin/tests/unit-tests/autosave-cleanup.php plugins/newspack-plugin/tests/unit-tests/traits/trait-autosave-fixtures.php`
Expected: no errors. Fix with `composer phpcbf -- <path>` if needed.

- [ ] **Step 6: Commit (only with Laurel's approval)**

```bash
git add plugins/newspack-plugin/includes/revisions-control/class-autosave-cleanup.php plugins/newspack-plugin/includes/revisions-control/class-revisions-control.php plugins/newspack-plugin/tests/unit-tests/autosave-cleanup.php plugins/newspack-plugin/tests/unit-tests/traits/trait-autosave-fixtures.php
git commit -m "fix(revisions): find and delete stale autosaves (NEWS-3071)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Daily cron

**Files:**
- Modify: `plugins/newspack-plugin/includes/revisions-control/class-autosave-cleanup.php`
- Test: `plugins/newspack-plugin/tests/unit-tests/autosave-cleanup.php`

**Interfaces:**
- Consumes: `get_days()`, `get_eligible_ids()`, `delete_autosaves()`, `BATCH_SIZE` from Task 1.
- Produces:
  - `Autosave_Cleanup::CRON_HOOK` (`'newspack_autosave_cleanup'`), `::MAX_PER_RUN` (500), `::LOGGER_HEADER`
  - `Autosave_Cleanup::init(): void`, `::cron_init(): void`, `::cron_deactivate(): void`
  - `Autosave_Cleanup::run_cron( int $max = self::MAX_PER_RUN ): int`, returning the number deleted

- [ ] **Step 1: Write the failing tests**

Append to the `Newspack_Test_Autosave_Cleanup` class:

```php
	/**
	 * The cron is scheduled daily.
	 */
	public function test_cron_is_scheduled_daily() {
		wp_clear_scheduled_hook( Autosave_Cleanup::CRON_HOOK );
		Autosave_Cleanup::cron_init();
		$this->assertNotFalse( wp_next_scheduled( Autosave_Cleanup::CRON_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Autosave_Cleanup::CRON_HOOK ) );
	}

	/**
	 * The cron deletes eligible autosaves and keeps the rest.
	 */
	public function test_run_cron_deletes_only_eligible() {
		[ , $eligible ] = $this->create_eligible_autosave();
		$post_id        = $this->create_post( 5 );
		$recent         = $this->create_revision( $post_id, 10, true );
		$this->create_revision( $post_id, 5 );

		$this->assertSame( 1, Autosave_Cleanup::run_cron() );
		$this->assertNull( get_post( $eligible ) );
		$this->assertNotNull( get_post( $recent ) );
	}

	/**
	 * The cron stops at the per-run cap.
	 */
	public function test_run_cron_respects_cap() {
		$this->create_eligible_autosave();
		$this->create_eligible_autosave();
		$this->create_eligible_autosave();

		$this->assertSame( 2, Autosave_Cleanup::run_cron( 2 ) );
		$this->assertCount( 1, Autosave_Cleanup::get_eligible_ids( 14, 100 ) );
	}

	/**
	 * A vetoed deletion is skipped, not retried forever or allowed to stop the run.
	 */
	public function test_run_cron_walks_past_vetoed_deletions() {
		[ , $vetoed ] = $this->create_eligible_autosave();
		[ , $other ]  = $this->create_eligible_autosave();

		$veto = function ( $check, $post ) use ( $vetoed ) {
			return $post->ID === $vetoed ? false : $check;
		};
		add_filter( 'pre_delete_post', $veto, 10, 2 );
		$deleted = Autosave_Cleanup::run_cron();
		remove_filter( 'pre_delete_post', $veto, 10 );

		$this->assertSame( 1, $deleted );
		$this->assertNotNull( get_post( $vetoed ) );
		$this->assertNull( get_post( $other ) );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `n test-php --filter Newspack_Test_Autosave_Cleanup`
Expected: the four new tests error with `Undefined constant Newspack\Autosave_Cleanup::CRON_HOOK` or `Call to undefined method … run_cron()`. The 12 Task 1 tests still pass.

- [ ] **Step 3: Write the minimal implementation**

In `class-autosave-cleanup.php`, replace the constants block with:

```php
	const DEFAULT_DAYS  = 14;
	const BATCH_SIZE    = 100;
	const MAX_PER_RUN   = 500;
	const CRON_HOOK     = 'newspack_autosave_cleanup';
	const LOGGER_HEADER = 'NEWSPACK-AUTOSAVE-CLEANUP';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'cron_init' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_cron' ] );
	}

	/**
	 * Schedule the daily cleanup, unless disabled with NEWSPACK_CRON_DISABLE.
	 */
	public static function cron_init() {
		register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'cron_deactivate' ] );

		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::cron_deactivate();
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the daily cleanup.
	 */
	public static function cron_deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Delete up to $max eligible autosaves in batches. Autosaves whose deletion
	 * is vetoed by another filter are skipped rather than retried.
	 *
	 * @param int $max Maximum autosaves to delete in this run.
	 * @return int Number deleted.
	 */
	public static function run_cron( $max = self::MAX_PER_RUN ) {
		$days     = self::get_days();
		$after_id = 0;
		$deleted  = 0;

		while ( $deleted < $max ) {
			$ids = self::get_eligible_ids( $days, min( self::BATCH_SIZE, $max - $deleted ), 0, $after_id );
			if ( empty( $ids ) ) {
				break;
			}
			$deleted += count( self::delete_autosaves( $ids ) );
			$after_id = end( $ids );
		}

		if ( $deleted ) {
			Logger::log( sprintf( 'Deleted %d stale autosaves.', $deleted ), self::LOGGER_HEADER );
		}

		return $deleted;
	}
```

At the end of the file, after the class's closing brace, add:

```php

Autosave_Cleanup::init();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `n test-php --filter Newspack_Test_Autosave_Cleanup`
Expected: PASS, 16 tests.

- [ ] **Step 5: Lint**

Run from the workspace root: `composer phpcs -- plugins/newspack-plugin/includes/revisions-control/class-autosave-cleanup.php plugins/newspack-plugin/tests/unit-tests/autosave-cleanup.php`
Expected: no errors.

- [ ] **Step 6: Commit (only with Laurel's approval)**

```bash
git add plugins/newspack-plugin/includes/revisions-control/class-autosave-cleanup.php plugins/newspack-plugin/tests/unit-tests/autosave-cleanup.php
git commit -m "fix(revisions): prune stale autosaves daily (NEWS-3071)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: `wp newspack autosaves prune`

**Files:**
- Create: `plugins/newspack-plugin/includes/cli/class-autosaves.php`
- Modify: `plugins/newspack-plugin/includes/cli/class-initializer.php` (the `include_once` list in `init()` and `register_comands()`)
- Test: `plugins/newspack-plugin/tests/unit-tests/autosave-cleanup-cli.php`

**Interfaces:**
- Consumes: `Autosave_Cleanup::get_days()`, `::get_eligible_ids()`, `::delete_autosaves()`, `::BATCH_SIZE`; test trait `Autosave_Fixtures` from Task 1.
- Produces: `Newspack\CLI\Autosaves::cmd_prune( array $args, array $assoc_args ): void`

- [ ] **Step 1: Write the failing tests**

Create `tests/unit-tests/autosave-cleanup-cli.php`, reusing the `Autosave_Fixtures` trait from Task 1:

```php
<?php
/**
 * Tests the autosave cleanup CLI command.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Autosaves;

require_once dirname( __DIR__ ) . '/mocks/wp-cli-mocks.php';
require_once __DIR__ . '/traits/trait-autosave-fixtures.php';
require_once NEWSPACK_ABSPATH . 'includes/cli/class-autosaves.php';

/**
 * Test the autosave cleanup CLI command.
 */
class Newspack_Test_Autosave_Cleanup_CLI extends WP_UnitTestCase {
	use Autosave_Fixtures;

	/**
	 * Reset recorded CLI output.
	 */
	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
	}

	/**
	 * Dry run reports but deletes nothing, and terminates.
	 */
	public function test_dry_run_deletes_nothing() {
		[ , $first ]  = $this->create_eligible_autosave();
		[ , $second ] = $this->create_eligible_autosave();

		Autosaves::cmd_prune( [], [ 'dry-run' => true ] );

		$this->assertNotNull( get_post( $first ) );
		$this->assertNotNull( get_post( $second ) );
		$this->assertContains( '2 stale autosaves would be deleted.', WP_CLI::$successes );
	}

	/**
	 * Without dry run, eligible autosaves are deleted.
	 */
	public function test_prune_deletes_eligible() {
		[ , $autosave_id ] = $this->create_eligible_autosave();

		Autosaves::cmd_prune( [], [] );

		$this->assertNull( get_post( $autosave_id ) );
		$this->assertContains( 'Deleted 1 stale autosaves.', WP_CLI::$successes );
	}

	/**
	 * --post limits the run to one post.
	 */
	public function test_post_flag_scopes_run() {
		[ $post_a, $autosave_a ] = $this->create_eligible_autosave();
		[ , $autosave_b ]        = $this->create_eligible_autosave();

		Autosaves::cmd_prune( [], [ 'post' => (string) $post_a ] );

		$this->assertNull( get_post( $autosave_a ) );
		$this->assertNotNull( get_post( $autosave_b ) );
	}

	/**
	 * --older-than=0 deletes recently stale autosaves but never fresh ones.
	 */
	public function test_older_than_zero_keeps_fresh() {
		$stale_post = $this->create_post( 1 );
		$stale      = $this->create_revision( $stale_post, 2, true );
		$this->create_revision( $stale_post, 1 );
		$fresh_post = $this->create_post( 60 );
		$fresh      = $this->create_revision( $fresh_post, 30, true );

		Autosaves::cmd_prune( [], [ 'older-than' => '0' ] );

		$this->assertNull( get_post( $stale ) );
		$this->assertNotNull( get_post( $fresh ) );
	}

	/**
	 * Invalid --older-than aborts.
	 */
	public function test_invalid_older_than_errors() {
		$this->expectException( WP_CLI_Mock_Exception::class );
		Autosaves::cmd_prune( [], [ 'older-than' => '-3' ] );
	}

	/**
	 * A missing post aborts.
	 */
	public function test_missing_post_errors() {
		$this->expectException( WP_CLI_Mock_Exception::class );
		Autosaves::cmd_prune( [], [ 'post' => '999999' ] );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `n test-php --filter Newspack_Test_Autosave_Cleanup_CLI`
Expected: fatal, `Failed opening required '…/includes/cli/class-autosaves.php'`.

- [ ] **Step 3: Write the minimal implementation**

Create `includes/cli/class-autosaves.php`:

```php
<?php
/**
 * CLI command to delete stale autosaves.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Autosave_Cleanup;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Autosaves CLI commands.
 */
class Autosaves {

	/**
	 * Delete autosaves that have been stale (overtaken by a later save) for a
	 * number of days. Fresh autosaves and major revisions are never deleted.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be deleted without deleting anything.
	 *
	 * [--post=<id>]
	 * : Only consider autosaves of this post.
	 *
	 * [--older-than=<days>]
	 * : Days an autosave must have been stale. Defaults to the cron setting (14). Use 0 for every stale autosave.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cmd_prune( $args, $assoc_args ) {
		$dry_run = ! empty( $assoc_args['dry-run'] );

		$post_id = 0;
		if ( isset( $assoc_args['post'] ) ) {
			$post_id = absint( $assoc_args['post'] );
			if ( ! $post_id || ! get_post( $post_id ) ) {
				WP_CLI::error( sprintf( 'Post %s not found.', $assoc_args['post'] ) );
			}
		}

		$days = Autosave_Cleanup::get_days();
		if ( isset( $assoc_args['older-than'] ) ) {
			if ( ! ctype_digit( (string) $assoc_args['older-than'] ) ) {
				WP_CLI::error( '--older-than must be a whole number of days, 0 or more.' );
			}
			$days = (int) $assoc_args['older-than'];
		}

		$after_id = 0;
		$count    = 0;
		while ( true ) {
			$ids = Autosave_Cleanup::get_eligible_ids( $days, Autosave_Cleanup::BATCH_SIZE, $post_id, $after_id );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				$autosave = get_post( $id );
				WP_CLI::log(
					sprintf(
						'%s autosave %d (post %d, user %d, saved %s GMT)',
						$dry_run ? 'Would delete' : 'Deleting',
						$id,
						$autosave->post_parent,
						$autosave->post_author,
						$autosave->post_modified_gmt
					)
				);
			}
			$count   += $dry_run ? count( $ids ) : count( Autosave_Cleanup::delete_autosaves( $ids ) );
			$after_id = end( $ids );
		}

		WP_CLI::success(
			$dry_run
				? sprintf( '%d stale autosaves would be deleted.', $count )
				: sprintf( 'Deleted %d stale autosaves.', $count )
		);
	}
}
```

In `includes/cli/class-initializer.php`:

1. In `init()`, after the last `include_once` line (`class-convert-subscription-variation.php`), add:

```php
		include_once NEWSPACK_ABSPATH . 'includes/cli/class-autosaves.php';
```

2. In `register_comands()`, before `Optional_Modules::register_commands();`, add:

```php
		WP_CLI::add_command(
			'newspack autosaves prune',
			[ 'Newspack\CLI\Autosaves', 'cmd_prune' ]
		);

```

Regenerate the classmap:

Run: `n composer dump-autoload`

- [ ] **Step 4: Run the tests to verify they pass**

Run: `n test-php --filter Newspack_Test_Autosave_Cleanup_CLI`
Expected: PASS, 6 tests.

- [ ] **Step 5: Try it against the dev site**

Run: `n wp newspack autosaves prune --dry-run`
Expected: `Success: 0 stale autosaves would be deleted.` (or a list, if the dev site has old autosaves). Nothing is deleted.

Run: `n wp help newspack autosaves prune`
Expected: the three options from the docblock are listed.

- [ ] **Step 6: Lint**

Run from the workspace root: `composer phpcs -- plugins/newspack-plugin/includes/cli/class-autosaves.php plugins/newspack-plugin/includes/cli/class-initializer.php plugins/newspack-plugin/tests/unit-tests/autosave-cleanup-cli.php`
Expected: no errors.

- [ ] **Step 7: Commit (only with Laurel's approval)**

```bash
git add plugins/newspack-plugin/includes/cli/class-autosaves.php plugins/newspack-plugin/includes/cli/class-initializer.php plugins/newspack-plugin/tests/unit-tests/autosave-cleanup-cli.php
git commit -m "fix(revisions): add wp newspack autosaves prune command (NEWS-3071)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Documentation, manual checks and full test run

**Files:**
- Modify: `plugins/newspack-plugin/includes/revisions-control/README.md` (append)

- [ ] **Step 1: Add the README section**

Append to `includes/revisions-control/README.md`:

````markdown

## Autosave cleanup

WordPress keeps one autosave per user per post and only cleans up an autosave when its own author opens the editor. Every other autosave stays, and the editor renders all of them each time it loads.

A daily cron (`newspack_autosave_cleanup`) deletes an autosave when:

- the post was saved after it (so the editor will never offer it back), and
- that happened at least 14 days ago, and
- it isn't marked as a major revision.

Fresh autosaves are never deleted. The cron deletes up to 500 autosaves per run. It runs whether or not the revision limit above is enabled.

Change the wait in `wp-config.php`:

```php
define( 'NEWSPACK_AUTOSAVE_CLEANUP_DAYS', 30 );
```

Disable the cron:

```php
define( 'NEWSPACK_CRON_DISABLE', [ 'newspack_autosave_cleanup' ] );
```

Run it by hand with WP-CLI:

```
wp newspack autosaves prune [--dry-run] [--post=<id>] [--older-than=<days>]
```

`--older-than=0` removes every stale autosave on the targeted posts. Fresh autosaves are still kept.
````

- [ ] **Step 2: Manually verify the constants**

These can't be tested in PHPUnit (see "Deviations from the spec").

1. Add `define( 'NEWSPACK_AUTOSAVE_CLEANUP_DAYS', 30 );` to `html/wp-config.php`.
   Run: `docker exec newspack_dev sh -c "wp eval 'echo Newspack\Autosave_Cleanup::get_days();' --allow-root"`
   Expected: `30`. Remove the line and re-run. Expected: `14`.
2. Add `define( 'NEWSPACK_CRON_DISABLE', [ 'newspack_autosave_cleanup' ] );`, load any admin page, then run `n wp cron event list --hook=newspack_autosave_cleanup`.
   Expected: no event. Remove the line, load an admin page, re-run. Expected: one `daily` event.

- [ ] **Step 3: Check the query plan**

Run:
```bash
docker exec newspack_dev sh -c "wp db query \"EXPLAIN SELECT a.ID FROM wp_posts a JOIN wp_posts p ON p.ID = a.post_parent WHERE a.post_type = 'revision' AND a.post_name = CONCAT( p.ID, '-autosave-v1' ) AND a.post_modified_gmt < p.post_modified_gmt ORDER BY a.ID LIMIT 100\" --allow-root"
```
Expected: `a` uses the `type_status_date` index (`key` column), `p` uses `PRIMARY`. Note the result for the PR description. Checking on a large production table is a separate step for Laurel to decide on (read-only, but on a live site).

- [ ] **Step 4: Full newspack-plugin test run**

Run: `n test-php`
Expected: all tests pass, including `revisions-control.php`, which is unchanged.

- [ ] **Step 5: Commit (only with Laurel's approval)**

```bash
git add plugins/newspack-plugin/includes/revisions-control/README.md
git commit -m "docs(revisions): document stale autosave cleanup (NEWS-3071)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## After the plan

- A PR (via `newspack:pr-create`, only when Laurel asks) with the PR template, a link to NEWS-3071, and a note that #1107 handles the Content Loop render.
- Parked, tracked in NEWS-3071: the revision limit (with the `min_age` timezone fix and publisher communication), and a new ticket for the Content Loop category query, drafted for Laurel's review.
