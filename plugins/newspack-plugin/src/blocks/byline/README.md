# Byline Block

A Gutenberg block that displays post author attribution with support for multiple authorship systems.

## Overview

The byline block automatically detects and displays the appropriate author information based on what's available for the post. It follows a priority-based approach to determine which byline source to use.

## Byline priority

The block checks for author information in the following order:

1. **Newspack Custom Bylines** - If the custom byline feature is enabled for the post (`_newspack_byline_active` meta), the block displays the custom byline content. Custom bylines support free-text editing with embedded author links using shortcode syntax.

2. **CoAuthors Plus** - If CoAuthors Plus is active and the post has authors assigned through CAP, the block displays all co-authors with proper formatting (e.g., "Author1, Author2, and Author3"). This works with both WordPress users and CAP Guest Authors.

3. **Default WordPress Author** - Falls back to the standard WordPress post author when neither custom bylines nor CoAuthors Plus authors are available.

## Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `prefix` | string | `"By"` | Text displayed before author names. A space is added automatically after the prefix. Hidden in inspector when custom byline is active. |
| `linkToAuthorArchive` | boolean | `true` | Whether to link author names to their archive pages. Hidden in inspector when custom byline is active. |

When a custom byline is enabled, both settings are hidden in the block inspector since custom bylines control their own prefix and link behavior.

## Editor behavior

The block reads from the CoAuthors Plus data store (`cap/authors`) for real-time updates in the editor. When authors are added or removed via the Authors panel in the sidebar, the byline block updates immediately without requiring a page refresh.

## Availability

This block replaces the Post Author block and is only available in block themes. For Newspack's classic theme, custom bylines are handled through the `pre_newspack_posted_by` filter, and CoAuthors Plus support is added through custom code.

## Usage with block theme patterns

The block theme post-meta patterns are configured to use this block when available:

```html
<!-- wp:newspack/byline {"prefix":"By"} /-->
```

For patterns that include avatars, use the `newspack/avatar` block as a sibling:

```html
<!-- wp:group {"layout":{"type":"flex"}} -->
<div class="wp-block-group">
  <!-- wp:newspack/avatar {"size":48} /-->
  <!-- wp:newspack/byline /-->
</div>
<!-- /wp:group -->
```

## Developer notes

For CAP authors, the block applies the `coauthors_posts_link` filter for each author link, matching CAP's own `coauthors_posts_links_single()` behavior.

## Related

- **Newspack Avatar Block** (`newspack/avatar`) - Displays author avatar(s) with support for CoAuthors Plus
- **Newspack Bylines Feature** - The underlying custom bylines system in Newspack (`includes/class-bylines.php`)
