# Avatar Block

A Gutenberg block that displays author avatars with support for multiple authorship systems.

## Overview

The Avatar block renders circular author profile pictures for the current post. It automatically detects the authorship system in use and displays the appropriate avatar(s). When multiple authors are present, each author gets their own avatar.

## Author resolution priority

The block follows the same priority as the [Byline block](../byline/README.md) to determine which avatars to display:

1. **Newspack Custom Bylines** - If the custom byline feature is enabled for the post (`_newspack_byline_active` meta) and the byline contains `[Author id="X"]` shortcodes, avatars are displayed for those authors. If the byline is active but contains only plain text (no shortcodes), no avatars are rendered.

2. **CoAuthors Plus** - If CAP is active and the post has co-authors, avatars are displayed for all of them. This works with both WordPress users and CAP Guest Authors.

3. **Default WordPress Author** - Falls back to the standard WordPress post author.

## Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `size` | number | `48` | Avatar image size in pixels. The actual image is fetched at 2x for retina displays. |
| `linkToAuthorArchive` | boolean | `false` | Whether to wrap the avatar in a link to the author's archive page. |

## Block styles
When CoAuthors Plus is active, a **Stacked** block style is registered. This overlaps avatars horizontally using negative margins and a radial mask, producing a compact, grouped layout for multi-author posts.

## Editor behavior

The editor uses two hooks to resolve avatar data:

- [usePostAuthors](./hooks.js) resolves the list of authors following the same priority as the PHP `get_avatar_authors()` method. Each author object includes an `avatarSrc` URL ready for rendering.

- [useUserAvatar](./hooks.js) provides the single WordPress post author avatar as a fallback when `usePostAuthors` returns an empty list.

For guest author avatars, the [useCoAuthors](../../shared/hooks/use-coauthors.js) hook fetches avatar URLs from the CAP REST endpoint (`/coauthors/v1/coauthors/{user_nicename}`). This is necessary because CAP's JS store strips avatar data. Results are cached at the module level by nicename, so adding a guest author displays their avatar immediately without requiring a post save.

In a Query Loop context, the block reads author data from the `newspack_author_info` REST field and extracts the author slug from `author_link` to fetch avatars from the same CAP endpoint.

## Availability

This block is only available in block themes. It replaces the core Avatar block in Newspack Block Theme patterns.

## Usage with block theme patterns

The block theme includes two post-meta patterns that use this block:

**Avatar, Byline, and Date on multiple lines** (48px avatar, stacked layout):
```html
<!-- wp:newspack/avatar {"size":48,"linkToAuthorArchive":true} /-->
```
Used by post header Style 1, which is the default for most single post templates (`single.html`, `large-image.html`, `wide-image.html`, `full-width-image.html`, `50-50-image.html`).

**Avatar, Byline, and Date on the same line** (24px avatar, compact horizontal layout):
```html
<!-- wp:newspack/avatar {"size":24,"linkToAuthorArchive":true} /-->
```
Used by post header Style 2, which is used in the `sidebar-layout.html` template.

Both patterns fall back to the core `avatar` and `post-author` blocks when the Newspack Plugin is not active.

## Styling

The block supports standard styles, and the avatar size is exposed as a `--avatar-size` CSS custom property on the wrapper, which is used by both the image sizing and the stacked style calculations.

## Related

- [Newspack Byline Block](../byline/README.md) - Displays author name attribution, designed to be used alongside this block.
- [Newspack Bylines Feature](../../../includes/bylines/class-bylines.php) - The underlying custom bylines system.
- [useCoAuthors hook](../../shared/hooks/use-coauthors.js) - Shared hook for CoAuthors Plus integration, used by both Avatar and Byline blocks.
