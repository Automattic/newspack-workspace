# Author Social Link Block

A Gutenberg block that displays a single social media icon link for the author.

## Overview

The Author Social Link block (`newspack/author-social-link`) renders a single social media or contact icon within the [Author Social Links](../author-profile-social/) parent block. Each instance represents a specific service (e.g., Facebook, email, phone) and automatically resolves the URL and icon from the author's profile data.

## Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `service` | string | `""` | Service key (e.g., `facebook`, `email`, `phone`, `linkedin`) |

## Context

| Context key | Direction | Description |
|-------------|-----------|-------------|
| `newspack-blocks/author` | Consumes | Author data object from the Author Profile block |
| `newspack-blocks/iconSize` | Consumes | Icon size in pixels from the parent Social Links block |

## Service resolution

The block resolves the URL for each service from the author data:

| Service | URL source |
|---------|------------|
| `email` | `author.email` (plain string becomes `mailto:`, object uses `.url`) |
| `phone` | `author.newspack_phone_number` (plain string becomes `tel:`, object uses `.url`) |
| Other services | `author.social[service].url` |

If the author has no data for the service, the block renders nothing (returns empty string on frontend, `null` in editor).

## Icon resolution

Icons are resolved in order:

1. **Author data SVG**: If the REST API provided an SVG with the author's social data (e.g., `author.social.facebook.svg`), it is used directly.
2. **Built-in SVG map**: Falls back to `Social_Icons::get_svg( $service )`, which provides SVG icons for all supported services.
3. **Text fallback**: If no SVG is available, the service name is displayed as plain text.

## Supported services

Labels are defined in [utils.js](./utils.js):

| Key | Label |
|-----|-------|
| `facebook` | Facebook |
| `twitter` | X |
| `instagram` | Instagram |
| `linkedin` | LinkedIn |
| `youtube` | YouTube |
| `bluesky` | Bluesky |
| `pinterest` | Pinterest |
| `myspace` | Myspace |
| `soundcloud` | SoundCloud |
| `tumblr` | Tumblr |
| `wikipedia` | Wikipedia |
| `email` | Email |
| `phone` | Phone |

Unknown service keys fall through to the raw key string as both label and fallback text.

## Availability

This block requires the Author Social Links block as its parent ([`newspack/author-profile-social`](../author-profile-social/)). It cannot be inserted independently.

## Related

- [Author Social Links Block](../author-profile-social/) - Parent block that manages the collection of social icons.
- [Author Profile Block](https://github.com/Automattic/newspack-blocks/tree/trunk/src/blocks/author-profile) - Root block that provides author context.
- [Social_Icons class](../../../includes/class-social-icons.php) - Backend SVG icon provider.
