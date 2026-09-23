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

WordPress keeps one autosave per user per post and only cleans up an autosave when its own author opens the editor. Every other autosave stays, and the editor renders all of them each time it loads.

A daily cron (`newspack_autosave_cleanup`) deletes an autosave when:

- the post was saved after it (so the editor will never offer it back), and
- that happened at least a day ago, and
- it isn't marked as a major revision.

Fresh autosaves are never deleted. The cron deletes up to 1,000 autosaves per run, newest first, so pages being edited now are cleared before an older backlog. It runs whether or not the revision limit above is enabled; when the limit is enabled, autosaves younger than its minimum age are also kept.

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
