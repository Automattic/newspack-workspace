=== Botcred Application Passwords ===
Contributors: jboydston
Tags: ai, ai-agents, mcp, application-passwords, api, rest-api, security
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scoped, per-agent application passwords for AI agents, MCP clients, and automation tools.

== Description ==

**Botcred Application Passwords** gives your AI agent, MCP client, or automation tool a secure, scoped credential to interact with your site — no code required.

Whether you're connecting Claude, ChatGPT, a custom MCP server, or an OpenClaw agent, Botcred gives you a one-click setup wizard that generates a properly scoped WordPress Application Password and logs every action the agent takes.

**Why Botcred?**

Most AI agent setups require digging into wp-config, creating users manually, or sharing admin credentials. Botcred removes all of that. Install, click, copy, paste — your agent is connected in under a minute.

**Features:**

* One-click connection setup under Settings → Botcred
* Generates a secure, scoped Application Password for your AI agent or MCP client
* Works with any agent that supports the WordPress REST API: Claude, ChatGPT, OpenClaw, n8n, Zapier, custom MCP servers, and more
* User-level and site-level logging of agent actions — see exactly what your agent did and when
* Displays credentials in ready-to-paste format (table and JSON)
* Shows connection status, creation date, and last used date
* One-click revoke with confirmation
* Clean, modern admin UI using native WordPress styles
* Proper security: nonces, capability checks, password shown only once

**Compatibility:**

* Works with the Model Context Protocol (MCP)
* Compatible with all major AI agent frameworks
* No third-party services or accounts required — everything stays on your site

== Installation ==

1. Upload the `botcred-application-passwords` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Settings → Botcred.
4. Click "Connect Agent" to generate your credentials.
5. Copy the credentials and paste them into your agent's config.

== Frequently Asked Questions ==

= What is an Application Password? =

Application Passwords are a built-in WordPress feature (since 5.6) that let external tools authenticate against the REST API without using your main account password. They can be revoked at any time without affecting your login.

= What agents and tools does this work with? =

Any tool that supports HTTP Basic Auth against the WordPress REST API. This includes Claude (via MCP), ChatGPT plugins, OpenClaw, n8n, Zapier, Make, custom Python scripts, and more.

= What is MCP? =

The Model Context Protocol (MCP) is an open standard for connecting AI agents to external tools and data sources. Botcred makes it easy to connect an MCP client to your site.

= Is the password stored anywhere? =

The plain-text password is shown once when created. WordPress stores only a hash, so the password cannot be retrieved later. If you lose it, revoke the old one and create a new connection.

= Can I have multiple agent connections? =

Botcred manages one Application Password per user. Revoke the existing one before creating a new connection, or create additional passwords directly in your WordPress profile.

== Screenshots ==

1. The "Not Connected" state with the one-click connect button.
2. The credentials display after creating a connection.
3. The "Connected" state showing status and revoke option.

== Changelog ==

= 2.0.1 =
* Renamed to Botcred Application Passwords for clarity and directory compliance.
* Updated text domain to botcred-application-passwords.

= 2.0.0 =
* Rebranded from ClawPress to Agent Access.
* Removed provisioner and theme-bridge (available as separate add-ons).
* Security hardening (escaping, nonces, input validation).
* Added object caching for content stats.
* Updated all namespaces, hooks, and REST routes.

= 1.1.0 =
* New: @mentions in comments — type @username to mention any site user.
* New: Mentioned users receive email notifications automatically.
* New: Mentions render as styled links in comment display.
* New: `agent_access_user_mentioned` action hook for integrations.
* New: `agent_access_send_mention_notification` filter to control notifications.

= 1.0.0 =
* Initial release.
* Create and revoke agent Application Passwords.
* Connection status display with creation and last-used dates.
* Copy-to-clipboard support for credentials.
