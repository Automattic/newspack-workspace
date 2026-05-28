# Republication Tracker Tool #
**Contributors:** innlabs, Automattic
**Tags:** publishers, news
**Requires at least:** 4.4
**Requires PHP:** 5.3
**Tested up to:** 6.2
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
