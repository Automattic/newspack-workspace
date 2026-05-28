# newspack-sponsors

[![semantic-release](https://img.shields.io/badge/%20%20%F0%9F%93%A6%F0%9F%9A%80-semantic--release-e10079.svg)](https://github.com/semantic-release/semantic-release) [![newspack-sponsors](https://circleci.com/gh/Automattic/newspack-sponsors/tree/trunk.svg?style=shield)](https://circleci.com/gh/Automattic/newspack-sponsors)

Create sponsors, edit sponsor info, and associate sponsors with posts, categories and tags. Allows special visual treatment for sponsored content.

## Usage

1. Activate this plugin.
2. In the WP admin dashboard, look for Sponsors.
3. Create and publish sponsors here. Add required info (content, sponsor logo, sponsor URL and byline prefix).
4. Once at least one sponsor has been published, you can assign sponsors directly to posts in the post edit screen. This is known as a direct sponsorship.
5. You may also assign categories and tags to a sponsor in the edit screen for a particular sponsor. This is known as a category or tag sponsorship. The sponsor will be associated with all posts that have those categories or tags.

## Development

Run `composer update && npm install`.

Run `npm run build`.

To use in a theme, check for the existence of the helper function first. Then call the helper function, optionally passing the ID of the post or term and the scope of the sponsors you want to get (if you don't provide params, the function will attempt to guess based on the current page):

```
if ( function_exists( '\Newspack_Sponsors\get_all_sponsors' ) ) {
	$sponsors = \Newspack_Sponsors\get_all_sponsors( get_the_ID(), 'native', [ 'maxwidth' => 130, 'maxheight' => 45 ] );
	var_dump( $sponsors );
}
```

Both helpers can be called from anywhere with a single argument with the post ID or term ID. If calling within a single post or archive page, the argument is optional and the current post ID or archive term ID will be used.

Both helpers will return an array containing all sponsors that are associated with the post or archive, and all info needed to display assets for each sponsor on the front-end. If the same sponsor applies as both a direct sponsor and a category or tag sponsor, that sponsor will appear multiple times but with the corresponding type in each case. Data returned for each sponsor:

- `sponsor_type` - Type of sponsorship: direct, category, or tag.
- `sponsor_scope` - The scope of sponsorship: native or underwritten. The distinction may differ between publishers, but this allows a different visual treatment for each.
- `sponsor_id` - Post ID of the sponsor in WP, in case you need to fetch more info on it.
- `sponsor_name` - Display name of the sponsor. This is the `post_title` of the sponsor.
- `sponsor_slug` - Slug of the sponsor. This is the `post_name` of the sponsor.
- `sponsor_blurb` - Content which can be displayed with any sponsored post. This is the `content` of the sponsor.
- `sponsor_url` - A URL to link to when displaying the Sponsor Name on the front-end.
- `sponsor_byline` - The copy shown in lieu of a byline on sponsored posts. This is combined with the `sponsor_name` to form a full byline. (Default: “Sponsored by” or “Paid for by”)
- `sponsor_logo` - Object containing properties for a medium-sized image to be displayed with any sponsored post. This is the `thumbnail` of the sponsor. Contains `src`, `img_width` and `img_height` properties.
- `sponsor_flag` - The label that appears in lieu of a category tag for sponsored content. This can be set site-wide and overridden per sponsor.
- `sponsor_disclaimer` - The disclaimer that explains sponsored content. This can be set site-wide and overridden per sponsor.
