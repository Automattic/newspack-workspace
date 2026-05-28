=== Republication Tracker Tool ===
Contributors: innlabs
Donate link: https://inn.org/donate
Tags: publishers, news
Requires at least: 5.3
Requires PHP: 7.4
Tested up to: 6.4.3
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Adds a widget to allow readers to easily acquire Creative-Commons-licensed HTML of articles to facilitate embedding posts on external sites. Includes a tracking mechanism similar to ProPublica's PixelPing.

== Description ==

A plugin that allows users to add a widget to allow readers to easily acquire Creative-Commons-licensed HTML of articles to facilitate embedding posts on external sites. Includes a tracking mechanism similar to ProPublica's PixelPing. Built and maintained by [INN Labs](https://labs.inn.org/).

== Installation ==

1. Activate the plugin through the 'Plugins' menu in WordPress
2. Add the widget to your per-post sidebars. It doesn't work outside of single post pages.

== Frequently Asked Questions ==

= How does the tracking mechanism work? =

The tracking mechanism is similiar to ProPublica's [PixelPing](https://www.propublica.org/pixelping) tracking technology.

In this plugin, the tracking is achieved through an image element included inside of the republishable content that collects data from the republishing site and sends that data to Google Analytics.

== Changelog ==

See https://github.com/Automattic/republication-tracker-tool/releases
