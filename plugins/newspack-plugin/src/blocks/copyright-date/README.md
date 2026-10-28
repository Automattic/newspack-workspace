# Copyright Block

A Gutenberg block that displays a copyright line: the current year with configurable prefix and suffix text. Its block name is `newspack/copyright-date`, from when it was titled Copyright Date; saved content references that name, so it stays.

## Overview

The Copyright block dynamically renders the current year based on the site's timezone setting. It's designed for use in footer templates and patterns, removing the need to manually update the copyright year. The year updates automatically for all visitors based on the site's configured timezone, not the visitor's local time.

## Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `prefix` | string | `"©"` | Plain text displayed before the year. Edited inline via a RichText field with formatting disabled. |
| `suffix` | string | `""` | Plain text displayed after the year. Edited inline via a RichText field with formatting disabled. |

## Editor behavior

The prefix and suffix are edited directly in the block content area using inline RichText fields with formatting disabled. There are no custom sidebar controls. The year is derived from `dateI18n('Y')` (`@wordpress/date`), which respects the site's timezone setting.

## Rendering

The block is server-rendered (dynamic block with no `save` function). The PHP render callback uses `wp_date('Y')` for the year, matching the editor's `dateI18n('Y')`. The output consists of up to three `<span>` elements (prefix, year, suffix) separated by spaces, wrapped in a `<div>` with block wrapper attributes.

Both prefix and suffix values are escaped with `esc_html()` in the render output. Empty prefix or suffix values are omitted from the markup entirely.

## Usage with block theme patterns

All footer patterns in the Newspack Block Theme include this block, with the site title as the suffix:

```php
<!-- wp:newspack/copyright-date <?php echo serialize_block_attributes( [ 'suffix' => get_bloginfo( 'name' ) ] ); ?> /-->
```

The suffix is plain text rather than a Site Title block, so editing the footer's wording doesn't change the site title. Once a footer is saved, the suffix no longer follows changes to the site title.

## Styling

The block has no stylesheet. All visual styling is controlled through the block's supported styles (typography, colors, spacing, borders) or inherited from the parent block.

## Related

- [Newspack Block Theme footer patterns](https://github.com/Automattic/newspack-block-theme/tree/trunk/patterns/footer) - Footer patterns that use this block.
