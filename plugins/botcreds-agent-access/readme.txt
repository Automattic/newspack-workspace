=== ClawPress ===
Contributors: openclaw
Tags: openclaw, application passwords, api, connection
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-click wizard to connect OpenClaw to your WordPress site via Application Passwords.

== Description ==

ClawPress provides a simple wp-admin wizard that lets you create and manage a secure Application Password for OpenClaw. No technical knowledge required — just click, copy, and paste.

**Features:**

* One-click connection setup under Settings → ClawPress
* Generates a secure Application Password named "OpenClaw"
* Displays credentials in a ready-to-paste format (table and JSON)
* Shows connection status, creation date, and last used date
* One-click revoke with confirmation
* Clean, modern admin UI using native WordPress styles
* Proper security: nonces, capability checks, and the password is shown only once

== Installation ==

1. Upload the `clawpress` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Settings → ClawPress.
4. Click "Connect OpenClaw" to generate your credentials.
5. Copy the credentials and paste them into your OpenClaw config.

== Frequently Asked Questions ==

= What WordPress version is required? =

ClawPress requires WordPress 5.6 or later, which introduced the Application Passwords API.

= Is the password stored anywhere? =

The plain-text password is shown once when created. WordPress stores only a hash, so the password cannot be retrieved later. If you lose it, revoke the old one and create a new connection.

= Can I have multiple OpenClaw connections? =

ClawPress manages a single Application Password named "OpenClaw" per user. Revoke the existing one before creating a new one.

== Screenshots ==

1. The "Not Connected" state with the one-click connect button.
2. The credentials display after creating a connection.
3. The "Connected" state showing status and revoke option.

== Changelog ==

= 1.0.0 =
* Initial release.
* Create and revoke OpenClaw Application Passwords.
* Connection status display with creation and last-used dates.
* Copy-to-clipboard support for credentials.
