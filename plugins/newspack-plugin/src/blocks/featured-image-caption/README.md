# Featured Image Caption block

Displays the featured image caption and photo credit for the current post. Designed for use in block theme templates and post-level patterns where the featured image is rendered separately from its metadata.

## Overview

The block reads the current post's featured image and combines its caption with an optional photo credit line. On the front end it renders a `<figcaption>` element; if neither a caption nor a credit exists, it renders nothing.

## Photo credit integration

When a featured image contains credit metadata, the block appends a formatted credit string after the caption. The exact wording and markup of this credit line (typically including a creator name and optional organization) is determined by `Newspack_Image_Credits::get_media_credit_string()` and its configuration, and may include HTML elements or links.

In the editor, credit data is read from the media object's `_media_credit` and `_navis_media_credit_org` meta fields. On the front end, credit rendering is delegated to `Newspack_Image_Credits::get_media_credit_string()`.

## Editor behavior

- When no featured image is set, a placeholder reads "Featured image caption."
- When a featured image exists but has no caption or credit, a placeholder reads "No caption or credit available."
- When data is available, the caption and credit are displayed together as they will appear on the front end.

## Front-end behavior

- If the post has no featured image, the block renders nothing.
- If the featured image has no caption and no credit, the block renders nothing.
- Otherwise, the block outputs a `<figcaption>` with the caption and credit.

## Supports

The block exposes the following block-supports controls — no custom attributes are registered:

- **Color** — background, text, link, and gradients.
- **Spacing** — margin and padding.
- **Typography** — font size, font family, font weight/style, line height, text transform, text decoration, letter spacing, and text alignment.

## Notes

- The block uses `postId` and `postType` context, so it must be placed inside a query loop or a template that provides post context.
- The block is server-side rendered; the `edit.js` component is for preview only.
