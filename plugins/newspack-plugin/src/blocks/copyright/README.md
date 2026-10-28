# Copyright Block

A Gutenberg block that displays a copyright line: the current year, optionally the site title, and configurable prefix and suffix text.

## Overview

The Copyright block dynamically renders the current year based on the site's timezone setting. It's designed for use in footer templates and patterns, removing the need to manually update the copyright year. The year updates automatically for all visitors based on the site's configured timezone, not the visitor's local time.

## Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `prefix` | string | `"©"` | Text displayed before the year. Edited inline; links are the only formatting allowed. |
| `showSiteTitle` | boolean | `false` | Displays the site title after the year, read at render time. |
| `linkSiteTitle` | boolean | `true` | Links the site title to the home page, through the `newspack_site_title_url` filter. Only applies when `showSiteTitle` is on. |
| `suffix` | string | `""` | Text displayed after the year and site title. Edited inline; links are the only formatting allowed. |

## Editor behavior

The prefix and suffix are edited directly in the block content area using inline RichText fields that allow links only. The site title is shown read-only, so editing the footer can't rename the site; the **Show site title** and **Link to home page** toggles in the sidebar control it. The year is derived from `dateI18n('Y')` (`@wordpress/date`), which respects the site's timezone setting.

## Rendering

The block is server-rendered (dynamic block with no `save` function). The PHP render callback uses `wp_date('Y')` for the year, matching the editor's `dateI18n('Y')`. The output consists of up to four `<span>` elements (prefix, year, site title, suffix), wrapped in a `<div>` with block wrapper attributes. A space separates the year from what follows, except before a suffix that starts with punctuation, so `, a project of Example Media` reads naturally.

The site title is read with `get_bloginfo( 'name' )` on every render, so it follows site title changes and per-brand names from Newspack Multibranded Site. Prefix and suffix values are filtered with `wp_kses()`, allowing links only. Empty prefix or suffix values are omitted from the markup entirely.

## Usage with block theme patterns

All footer patterns in the Newspack Block Theme include this block with the site title shown:

```html
<!-- wp:newspack/copyright {"showSiteTitle":true} /-->
```

## Styling

The block has no stylesheet. All visual styling is controlled through the block's supported styles (typography, colors, spacing, borders) or inherited from the parent block.

## Related

- [Newspack Block Theme footer patterns](https://github.com/Automattic/newspack-block-theme/tree/trunk/patterns/footer) - Footer patterns that use this block.
