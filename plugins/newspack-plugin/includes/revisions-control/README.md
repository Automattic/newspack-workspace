# Revisions Control

This feature allows sites to limit the number of revisions kept for each post.

## How it works

Everytime a post is saved, WordPress check the maximum number of revisions should be kept in the database. By default, there's no limit.

When you enable this feature, you will set the max number of revisions you want to preserve in the database. If there are more than the set maximum, older revisions will be deleted.

This feature also takes into consideration the age of the revision. By default, a revisions will be deleted only if it is more than 1 week old. So even if the number of existing revisions exceeds the maximum number set, revisions will not be deleted if they are not old enough.

See bellow how to change these settings.

## Usage

There are two ways to enable it.

### Using a Constant

Add this to your `wp-config.php`:

```php
define( 'NEWSPACK_LIMIT_REVISIONS_NUMBER', 30 );
```

Replace `30` with the maximum number of revisions you want to keep.

When using the constant, the minimum age a revision must have to be deleted will default to 1 week.

### Using an option

You can also add an option to control the feature and it will override the value in the constant:

```php
update_option(
	'newspack_revisions_control',
	[
		'active'  => true,
		'number'  => 30,
		'min_age' => '-1 week',
	]
);
```

When setting the option you can define the maximum number of revisions to be kept as well as the minimum age a revision must have to be deleted. Accepted values are string compatible with the PHP DateTime modifiers. Example: '-1 day', '-1 month', '-2 months'.

## Autosave cleanup

WordPress keeps one autosave per user per post. When anyone opens the editor, WordPress checks only the newest autosave on the post and deletes it if it's stale, so older autosaves pile up behind it. The editor renders all of them each time it loads.

A daily cron (`newspack_autosave_cleanup`) deletes an autosave when:

- the post was saved after it (so the editor will never offer it back), and
- that happened at least a day ago, and
- it isn't marked as a major revision.

Fresh autosaves are never deleted. The cron deletes up to 1,000 autosaves per run, newest first, so pages being edited now are cleared before an older backlog. It runs whether or not the revision limit above is enabled, and the limit's minimum age doesn't apply to autosaves: WordPress only deletes an autosave it no longer needs (stale, identical to the post, or with its parent).

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

## Trimming revisions over the limit

WordPress trims a post to the limit each time it's saved. When the limit is lowered, a single save can delete hundreds of revisions and take several seconds. Instead:

- Each save deletes at most 10 revisions, the oldest first.
- An hourly cron (`newspack_revision_cleanup`) deletes up to 500 more per run. It works through posts in ID order, picking up where the last run stopped, and starts over after the last one.

Both skip revisions under the minimum age, major revisions and autosaves. The cron only runs while the limit is enabled and not unlimited.

Disable the cron:

```php
define( 'NEWSPACK_CRON_DISABLE', [ 'newspack_revision_cleanup' ] );
```

Run it by hand with WP-CLI:

```
wp newspack revisions prune [--dry-run] [--post=<id>]
```
