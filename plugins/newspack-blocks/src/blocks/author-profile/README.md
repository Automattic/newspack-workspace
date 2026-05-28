# Author Profile Block

A Gutenberg block that displays author bio cards with support for multiple authorship systems and two rendering modes.

## Overview

The Author Profile block renders a complete author profile, including name, avatar, biography, social links, and a link to their archive page. It supports two operating modes (contextual and specific) and two rendering modes (nested and flat), adapting automatically to the theme type.

## Operating modes

### Contextual mode

Automatically detects authors for the current post. Resolution priority:

1. **Newspack Custom Bylines:** If `_newspack_byline_active` meta is set and the byline contains `[Author id="X"]` shortcodes, profiles are shown for those authors. Plain-text-only bylines suppress the block entirely.
2. **CoAuthors Plus:** If CAP is active, all co-authors are displayed (WordPress users and Guest Authors).
3. **Default WordPress author:** Falls back to the standard post author.

### Specific mode

Displays a single author selected via a search/autocomplete interface. The selected author ID and guest author flag are stored as block attributes.

## Rendering modes

### Nested mode (layoutVersion 2)

Used in block themes. The block saves `<InnerBlocks.Content />` and composes the profile from core blocks (Columns, Group, Heading, Paragraph) plus [`newspack/avatar`](https://github.com/Automattic/newspack-plugin/tree/trunk/src/blocks/avatar) and [`newspack/author-profile-social`](https://github.com/Automattic/newspack-plugin/tree/trunk/src/blocks/author-profile-social). Author data is injected via WordPress block bindings (`newspack-blocks/author` source) and block context.

**Layout variations:**

| Variation | Template constant | Description |
|-----------|------------------|-------------|
| `avatar-left` | `AVATAR_LEFT_TEMPLATE` | Two-column: avatar left, content right |
| `avatar-right` | `AVATAR_RIGHT_TEMPLATE` | Two-column: content left, avatar right |
| `centered` | `CENTERED_TEMPLATE` | Flex group: centered avatar, centered text |
| `compact` | `COMPACT_TEMPLATE` | Flex group: no avatar, vertical stack |

Variations are defined in [variations.js](./variations.js) and templates in [templates.js](./templates.js).

### Flat mode (layoutVersion 1)

Used in classic themes. The block returns `save: () => null` and renders entirely via PHP using the [author-profile-card.php](../../templates/author-profile-card.php) template.

**Mode selection logic:** New blocks in block themes automatically get `layoutVersion: 2`. Existing blocks preserve their saved mode. A v2 block opened in a classic theme shows a warning in the editor and falls back to flat rendering on the frontend.

## Block attributes

### Core attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `authorId` | number | `0` | Selected author ID (specific mode) |
| `isContextual` | boolean | `false` | Whether to auto-detect authors from post context |
| `isGuestAuthor` | boolean | `true` | Whether the selected author is a CAP guest author |
| `layoutVersion` | number | `1` | `1` = flat, `2` = nested (see Rendering modes) |
| `variation` | string | `""` | Active layout variation name |
| `showEmptyBio` | boolean | `true` | Show profiles for authors without bios |
| `avatarHideDefault` | boolean | `false` | Hide default placeholder avatar |

### Flat mode attributes

These only apply in flat mode (`layoutVersion: 1`). In nested mode, display is controlled by adding or removing inner blocks.

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `showBio` | boolean | `true` | Display biography |
| `showSocial` | boolean | `true` | Display social links |
| `showEmail` | boolean | `true` | Display email link |
| `showArchiveLink` | boolean | `true` | Display "More by Author" link |
| `showAvatar` | boolean | `true` | Display avatar |
| `textSize` | string | `"medium"` | Text size: small, medium, large, extra-large |
| `avatarAlignment` | string | `"left"` | Avatar position: left or right |
| `avatarSize` | number | `128` | Avatar size in pixels |
| `avatarBorderRadius` | string | `"50%"` | Avatar border radius |

Additional `show*` attributes are dynamically added from Newspack Author Custom Fields when available (e.g., `shownewspack_job_title`, `shownewspack_role`, `shownewspack_employer`).

## Block bindings

The `newspack-blocks/author` binding source is registered in both PHP ([view.php](./view.php)) and JS ([edit.js](./edit.js)). Supported keys:

| Key | Description |
|-----|-------------|
| `name` | Author display name |
| `bio` | Author biography |
| `url` / `archive_url` | Author archive URL |
| `archive_link_text` | "More by Author Name" text |
| `newspack_job_title` | Job title (custom field) |
| `newspack_role` | Role (custom field) |
| `newspack_employer` | Employer (custom field) |

In the editor, bindings resolve via a per-instance author map (`window.__newspackAuthorsByBlock`) keyed by `clientId`, so multiple Author Profile blocks on the same page don't conflict.

## Editor behavior

- **Variation picker**: Shown when the block has no inner blocks (nested mode). Follows the same pattern as the core Columns block.
- **Author context**: The [AuthorContext](./context.js) React context passes author data to inner blocks. It's also exposed globally as `window.NewspackAuthorContext` for cross-package use (e.g., by the avatar block in newspack-plugin).
- **Template context**: In the Site Editor (templates/template parts), a placeholder author is shown with all supported social services populated.

## Related

- [Author Social Links Block](https://github.com/Automattic/newspack-plugin/tree/trunk/src/blocks/author-profile-social) - Inner block for social media icons.
- [Avatar Block](https://github.com/Automattic/newspack-plugin/tree/trunk/src/blocks/avatar) - Inner block for author avatars.
- [Author Profile Card Template](../../templates/author-profile-card.php) - PHP template for flat-mode rendering.
- [Author List Block](../author-list/) - Directory-style author listing (different use case).
