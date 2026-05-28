# Author Social Links Block

A Gutenberg block that displays the author's social media links and contact information as a row of linked icons.

## Overview

The Author Social Links block (`newspack/author-profile-social`) renders social media icons for the current author. It is designed exclusively as an inner block of the [Author Profile block](https://github.com/Automattic/newspack-blocks/tree/trunk/src/blocks/author-profile) and appears only in the nested layout mode of the block, available when using a block theme.

Each social link is a separate [Author Social Link](../author-social-link/) child block, allowing publishers to reorder or remove individual links.

## Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `showEmail` | boolean | `false` | Whether to display the author's email as a link |
| `iconSize` | number | `24` | Icon size in pixels. Rounded to the nearest even number for rendering |

## Context

| Context key | Direction | Description |
|-------------|-----------|-------------|
| `newspack-blocks/author` | Consumes | Author data object from the parent Author Profile block |
| `newspack-blocks/iconSize` | Provides | Icon size passed down to child Author Social Link blocks |

## Supported services

The block displays icons for any social service present in the author's profile data. Common services include: Facebook, X (Twitter), Instagram, LinkedIn, YouTube, Bluesky, Pinterest, Myspace, SoundCloud, Tumblr, Wikipedia, email, and phone.

## Editor behavior

- **Auto-population**: On first render (when no saved inner blocks exist), the block fetches the full list of supported services from the `/newspack/v1/social-icons` REST endpoint and creates an `Author Social Link` inner block for each service. This ensures all possible icons are available in the template; icons without author data are hidden on the frontend.
- **Reset button**: A toolbar button resets inner blocks to the full set of available services.
- **Add missing links**: If the author has services that don't have a corresponding inner block, an "Add missing links" button appears in the inspector panel.
- **Empty state**: When the author has no social links and no inner blocks exist, a placeholder message is shown.

## Icon size

The icon size is configurable via a dropdown in the inspector panel. Available options are defined in `getIconSizeOptions()` in [utils.js](./utils.js). Values are stored as-is but rounded to the nearest even number for display (e.g., 23 renders as 24px). The size is exposed as a `--icon-size` CSS custom property on the wrapper.

## Rendering

### Frontend (PHP)

Two rendering paths exist in [class-author-profile-social-block.php](./class-author-profile-social-block.php):

1. **InnerBlocks mode**: When saved inner blocks exist, each `Author Social Link` child block is rendered with the author context propagated.
2. **Flat mode**: Legacy fallback that builds social links directly from the author data array without inner blocks.

Both modes output a `<ul class="author-profile-social__list">` wrapper.

### Editor (React)

The [edit.jsx](./edit.jsx) component uses `InnerBlocks` with `allowedBlocks` restricted to `newspack/author-social-link`. Author data is consumed from the shared `AuthorContext` (via `window.NewspackAuthorContext`).

## Availability

This block is only registered for the inserter in block themes. It requires the Author Profile block as an ancestor ([`newspack-blocks/author-profile`](https://github.com/Automattic/newspack-blocks/tree/trunk/src/blocks/author-profile)).

## Related

- [Author Social Link Block](../author-social-link/) - Child block for individual social icons.
- [Author Profile Block](https://github.com/Automattic/newspack-blocks/tree/trunk/src/blocks/author-profile) - Parent block that provides author context.
- [Social_Icons class](../../../includes/class-social-icons.php) - Backend SVG icon provider.
