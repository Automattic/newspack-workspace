# Ad Display Settings

The Super Cool Ad Inserter provides lots of flexibility in how and where ads are placed within posts. By default, 2 ads will be inserted automatically in a post, beginning 3 blocks after the beginning and every 3 blocks after that. They will not appear if the post is shorter than 6 blocks. You can easily change these default settings for ad automatic insertion in the [Ad Inserter Plugin Options panel](configuration.md).

## Prevent Automatic Insertion of Ads in a Post

By default, once [configured](configuration.md) SCAIP will insert ads in every post. But you may have posts for which you want to exclude advertising. This is simple to do using the Super Cool Ad inserter post meta box that appears in the post editing page:

![Super Cool Ad inserter post meta box](./img/scaip-post-meta-box.png)

You can check the box to "prevent automatic addition of ads to this post" or add the shortcode `[ad no]` at the start of the story. In either case, no ads will appear in the post. If you uninstall the Super Cool Ad Inserter Plugin, the `[ad no]` shortcodes will remain in your posts, so it is recommended to use the checkbox instead of `[ad no]`.

## Manual Ad Placement

You can override automatic placement of ads by inserting ad placements in the body of the post, wherever you want the ads placed. Sites using Gutenberg may use blocks; all WordPress sites may use shortcodes.

### Blocks

Each block requires you to set the sidebar that will be output. You can optionally set the widget area's alignment using Gutenberg's alignment tools, or set additional CSS classes to be output on the `aside` that contains the sidebar output.

![A screenshot of the Inserted Ad Position Block showing its settings in use.](./img/block.png)

### Shortcodes

For example, to display the Inserted Ad Position 2 in a custom location in your post, you would use the following shortcode:

```

A paragraph of text, or an image, or anything else that you would use in a story.

[ad number="2"]

The next paragraph, block quote, image, embed, link, or really anything else.

```

In this case the post will display the ad in Inserted Ad Position 2 wherever you placed the shortcode, and no other ads will automatically display. This allows you to have many Inserted Ad Positions available for some posts when needed, but display fewer ads with certain posts.

You can also reorder the display of Inserted Ad Positions by placing shortcodes for each wherever you want in the post.

Shortcodes must have an empty line between the shortcode and anything before or after the shortcode.

The full set of shortcode arguments is as follows:

- `number=""`: required, a number from one to the value set in **Settings > Ad Inserter**, under "Number of times the ad should be inserted in a post."
- `align=""`: optional, any of WordPress' [alignment classes](https://codex.wordpress.org/CSS) with the 'align' prfix removed: `left`, `right`, `center`, `wide`, `full`, `none`.
- `class=""`: optional, CSS class names.

## Ad Spacing Using Empty Inserted Ad Positions

You can add  Widgets to each and every numbered Inserted Ad Position, and they will display every nth paragraph based on the number of blocks you defined in **Plugins** > **Ad Inserter**  [options](configuration.md).

But sometimes you might want to use the default automatic ad insertion but protect larger groups of blocks from getting interrupted by an ad. Let's say the ads are set to display every 3 blocks, which means the first ad will appear 3 blocks from the beginning of the post. You can use an empty Inserted Ad Position to reserve that position without displaying an ad. In this case, we'd leave Inserted Ad Position 1 blank:

![empty Inserted Ad position](./img/scaip-widget-area-empty.png)

Note that we haven't even added an empty Text Widget to this Ad Position, but simply left it as an empty widget area. On the post page, the first ad won't display until after 6 blocks:

![post with six blocks before the first ad](./img/scaip-ad-after-six-paras.png)

## Developer options

For more customizability, see [the developer documentation](./developers-shortcode-docs.md).
