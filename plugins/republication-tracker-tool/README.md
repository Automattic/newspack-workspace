# Republication Tracker Tool #
**Contributors:** innlabs, Automattic
**Tags:** publishers, news
**Requires at least:** 6.9
**Requires PHP:** 7.4
**Tested up to:** 7.0
**Stable tag:** 1.0.2
**License:** GPLv2 or later
**License URI:** http://www.gnu.org/licenses/gpl-2.0.html

Adds a widget to allow readers to easily acquire Creative-Commons-licensed HTML of articles to facilitate embedding posts on external sites. Includes a tracking mechanism similar to ProPublica's PixelPing.

## Description ##

A plugin that allows users to add a widget to allow readers to easily acquire Creative-Commons-licensed HTML of articles to facilitate embedding posts on external sites. Includes a tracking mechanism similar to ProPublica's PixelPing. Built by [INN Labs](https://labs.inn.org/), now maintained by [Newspack](https://newspack.com/) and [Automattic](https://automattic.com/).

## Installation ##

1. Activate the plugin through the 'Plugins' menu in WordPress.
2. Configure plugin settings in the 'Settings' > 'Reading' menu.
3. Add the widget to your per-post sidebars. It doesn't work outside of single post pages.

## Frequently Asked Questions ##

### How does the tracking mechanism work? ###

The tracking mechanism is similiar to ProPublica's [PixelPing](https://www.propublica.org/pixelping) tracking technology.

In this plugin, the tracking is achieved through an image element included inside of the republishable content that collects data from the republishing site and sends that data to Google Analytics. Shared content views are tracked as pageview events in Google Analytics, with the shared URL listed as referrer. Supports both Universal Analytics and Google Analytics 4 protocols.

### How are views counted? ###

The "views" number counts requests to the tracking pixel. Because it counts image requests rather than analytics-verified pageviews, it will not match analytics products like Parse.ly or Google Analytics, which apply their own (stricter) filtering — expect the pixel count to read somewhat higher.

With the counting guards enabled (via the `WPRTT_COUNTING_GUARDS_ENABLED` constant or the `wprtt_counting_guards_enabled` filter), the way the displayed count is calculated does not change: the plugin starts recording a second, guarded count alongside it, in which requests from known bots, crawlers, and link-preview agents are excluded and repeat views of the same story by the same reader on the same republishing site within 30 minutes count once (matching the session windows used by analytics products). Comparing the two counts on real traffic shows what the guards would change before the displayed count switches to the guarded calculation. The guards also make pixel responses uncacheable so every request reaches the counter — which means the displayed count can read somewhat higher on busy stories while the guards are on, since requests that page caches used to absorb now arrive.

The guarded count is not shown in the admin. Read it with WP-CLI during a comparison:

```
wp post meta get <post-id> republication_tracker_tool_sharing_guarded
wp post meta get <post-id> republication_tracker_tool_sharing_guarded_baseline
```

The baseline records the raw counter as it stood when guarded counting started for the post, plus a start timestamp. Because the raw counter carries the story's full history while the guarded count starts at zero, the like-for-like comparison is the guarded count against the raw count *minus its baseline value* — both cover the same period. They are off by default.
