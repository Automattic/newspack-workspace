# Newspack Campaigns Segmentation

When used alongside the main [Newspack Plugin](https://github.com/Automattic/newspack-plugin/), the Newspack Campaigns plugin enables features to target different segments of readers based on historical and real-time activity. This allows for highly configurable targeted messaging using different combinations of prompts and segments.

This doc describes technical details about how segmentation works, and should hopefully explain how the plugin decides whether to show a given prompt to a given reader.

## Technical Details

### Reader Data Library

Real-time reader data handling is enabled through the [Reader Data Library](https://github.com/Automattic/newspack-plugin/blob/trunk/includes/reader-activation/class-reader-data.php) and [Data Events API](https://github.com/Automattic/newspack-plugin/blob/trunk/includes/data-events/README.md) in the main Newspack Plugin. Here's a brief description of how reader data lets Newspack Campaigns target readers based on different segmentation criteria:

1. **Reader initiates a new browser session** - The Reader Data Library creates a persistent data session to track their activity on the site. Reader Data is used by many Newspack products to share relevant reader data, and is stored in `localStorage`. This reader data can be persisted to the server via reader account registration that may happen through Woocommerce purchases or the [Reader Activation System](https://help.newspack.com/engagement/reader-activation-system/).

2. **Reader views a new page** - Any pageview request. This is tracked in the Reader Data store as a simple aggregated count in day, week, and month periods so that Campaigns prompts can be shown at different frequencies, e.g. every other pageview.

3. **Reader signs up for a newsletter** - When a reader signs up for a newsletter via the [Newspack Newsletters](https://github.com/Automattic/newspack-newsletters/) signup form block, the Data Events API dispatches a [`newsletter_subscribed` event](https://github.com/Automattic/newspack-plugin/blob/trunk/includes/data-events/README.md#newsletter_subscribed) which updates the `is_newsletter_subscriber` and the `newsletter_subscribed_lists` reader data items, which consists of the lists the reader is subscribed to. If [Reader Activation](https://help.newspack.com/engagement/reader-activation-system/) is enabled for the site, this action also results in a reader account registration.

4. **Reader makes a donation** - When a reader completes a one-time or recurring donation via the [Newspack Donation block](https://github.com/Automattic/newspack-blocks/tree/trunk/src/blocks/donate), Data Events dispatches a [`donation_new` event](https://github.com/Automattic/newspack-plugin/blob/trunk/includes/data-events/README.md#donation_new) which updates the Reader Data object with an `is_donor` key. If [Reader Activation](https://help.newspack.com/engagement/reader-activation-system/) is enabled for the site, this action also results in a reader account registration.

5. **Reader registers for a new account** -  If [Reader Activation](https://help.newspack.com/engagement/reader-activation-system/) is enabled for the site, reader account registration is enabled. When a reader registers for a new account, Data Events dispatches a [`reader_registered` event](https://github.com/Automattic/newspack-plugin/blob/trunk/includes/data-events/README.md#reader_registered) which ties the Reader Data store to the reader account. Reader data in `localStorage` is synced to the reader account so that this data can persist after the current browser session ends.

6. **Reader logs into an existing account** - When a reader logs into an existing reader account, Data Events dispatches a [`reader_logged_in` event](https://github.com/Automattic/newspack-plugin/blob/trunk/includes/data-events/README.md#reader_logged_in) which lets the Reader Data store rehydrate itself with data synced to the reader's account.

For performance reasons, reader data activity items in `localStorage` will persist for a maximum of 30 days, or until the number of reader data activity items hits 1000. After 1000, the oldest items will be purged to remain at or below the 1000 item limit. Non-activity data will persist until the browser session ends.

Reader data that is synced to a reader account will persist as long as the reader account exists.

### Segments and segmentation criteria

Segments are collections of criteria. A criteria (always plural so it's easy to find in code) is an abstract object that describes a reader's state based on historical or current activity. The info required for the criteria to be used for reader segmentation includes:

- `name`: The name of the criteria, which can be used to identify it across the Newspack Campaigns plugin.
- `matching_function`: A function to be used for determining whether a reader matches the criteria. This can be any arbitrary function, but the plugin includes several [default matching functions](https://github.com/Automattic/newspack-popups/blob/trunk/src/criteria/matching-functions.js) suitable for most purposes. The `matching_function` must return a boolean value representing whether the reader matches the criteria.
- `matching_attribute:` Either the attribute name to match from the reader data library store or a function that returns the value. This is the value that is piped to the `matching_function` to determine a match.

The Newspack Campaigns plugin includes [several default criteria](https://github.com/Automattic/newspack-popups/tree/trunk/src/criteria/default).

A segment can consist of one or more criteria. A reader must pass the `matching_function` for all criteria in a segment in order to match that segment.

### Prompts and segments

Prompts can be assigned to zero or more segments. If a prompt is assigned to zero segments, it will be shown to all readers ("Everyone"), without regard to segmentation.

### Segments and priority

Segments are assigned a priority in the **Campaigns > Segments** dashboard. The single matching segment with the highest priority is the reader's **best-priority matching segment**. Readers will only be shown prompts that are assigned to this single segment, as well as prompts assigned to no segment ("Everyone")—even if they also match other segments with lower priority.

This is to avoid showing potentially conflicting messaging to readers who match more than one segment.

### Prompts and frequency

Prompts can also be set to display with a given frequency. Configure frequency settings in prompt editor. If a prompt has frequency settings, it will only be shown to readers if the prompt is assigned to the reader's best-priority matching segment, AND it passes the frequency check.

## How the Segmentation API works

The segmentation API is executed in JavaScript and affects only the reader's local browser session, except when syncing data to or from a reader account. The following describes the sequence of events in this plugin from when a reader first visits the site until they're shown prompts matching their audience segment.

1. Reader visits a page on the site.
2. If the Reader Data Library is available, we log a pageview by incrementing the count in a store object called `pageviews`. This object tracks total number of pageviews in the past day, week, and month.
3. We find the reader's best-priority matching segment by looping through each segment in priority order until we have a match. It's possible that the reader will not match any segments, in which case they will only see prompts that are not assigned any segments.
3. We find all prompts on the current page. Prompt HTML is injected into the markup via [this Inserter class](https://github.com/Automattic/newspack-popups/blob/trunk/includes/class-newspack-popups-inserter.php). All prompts are wrapped in an element with the class name `.newspack-popup-container` and are hidden by default via CSS.
4. Looping through each prompt, we look up IDs for the segments that the prompt is assigned to in a `data-segments` attribute on the `.newspack-popup-container` wrapper element.
5. We match the prompt's segment IDs to segment configuration, which is [localized to the segmentation API JS](https://github.com/Automattic/newspack-popups/blob/trunk/includes/class-newspack-popups-inserter.php).
6. If the prompt passes frequency checks and is assigned to the reader's best-priority matching segment, OR the prompt is assigned to no segments, the prompt is eligible for display and unhidden by removing the `.hidden` class from the `.newspack-popup-container` wrapper element.
7. Only one overlay prompt will be displayed per pageview, even if multiple overlay prompts are eligible for display. This is to avoid overlapping modal elements. Generally, the most recently published overlay will be the one shown if multiple are eligible for display.
8. Once a prompt is displayed, if a reader interacts with that prompt by scrolling it into the viewport, dismissing it, or clicking links or submitting forms inside its content, the interaction is logged to the reader data store as these interactions may affect the prompt's eligibility for display in future pageviews.

### Debugging the segmentation API.

In your `wp-config.php`, define `WP_DEBUG` or `NEWSPACK_POPUPS_DEBUG` as `true`,  or `NEWSPACK_LOG_LEVEL` as `2` or greater.

When viewing the site's front-end, you can now inspect the global `newspack_popups_debug` object for information about the all of the prompts on the current page. The debug object is keyed by each prompt's ID, and contains the following info for each prompt:

- `displayed`: Whether or not the prompt was displayed
- `element`: A live reference to the HTMLElement of the prompt
- `override`: If the prompt was forced to be displayed or suppressed via override, this will show the boolean value of the override.
- `suppression`: If the prompt was not displayed, this will be an array containing all the reasons it was suppressed.
