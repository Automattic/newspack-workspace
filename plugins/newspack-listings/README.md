# newspack-listings

[![semantic-release](https://img.shields.io/badge/%20%20%F0%9F%93%A6%F0%9F%9A%80-semantic--release-e10079.svg)](https://github.com/semantic-release/semantic-release) [![newspack-listings](https://circleci.com/gh/Automattic/newspack-listings/tree/trunk.svg?style=shield)](https://circleci.com/gh/Automattic/newspack-listings)

Create reusable content as listings and add them to lists wherever core blocks can be used. Create static, curated lists or dynamic, auto-updating lists with optional "load more" functionality. Edit display options to control how the list looks and behaves for readers. Compatible with [AMP](https://amp.dev/).

## Usage

1. Activate this plugin.
2. In the WP admin dashboard, look for Listings.
3. Create and publish listings of any type. Listings can contain any core blocks as content.
4. Optionally tag or categorize your listings to keep them organized, even across different listing types.
5. Once at least one listing is published, add a Curated List block to any post or page.
6. Choose Specific Listings mode to create a static list, or Query mode to create a dynamic list which will automatically update itself when new listings matching the query options are published.
7. Edit list options to control the list's display and behavior.

For more detailed instructions, refer to the [public documentation for Newspack Listings](https://help.newspack.com/engagement/listings/).

## Development

Run `composer update && npm install`.

Run `npm run build`.

Each listing type is a separate custom post type. Configuration is in `includes/newspack-listings-core.php`.

Metadata for listing CPTs is synced from certain blocks in the post content. See configuration in `includes/newspack-listings-core.php` for details.
