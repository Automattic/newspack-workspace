=== BotCreds Agent Access ===
Contributors: jboydston, droyal
Tags: ai-agents, mcp, application-passwords, rest-api, security
Requires at least: 5.7
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.2.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scoped, per-agent application passwords for AI agents, MCP clients, and automation tools.

== Description ==

**BotCreds Agent Access** gives your AI agent, MCP client, or automation tool a secure, scoped credential to interact with your site — no code required.

Whether you're connecting Claude, ChatGPT, a custom MCP server, or an OpenClaw agent, BotCreds gives you a one-click setup wizard that generates a properly scoped WordPress Application Password and logs every action the agent takes.

**Why BotCreds?**

Most AI agent setups require digging into wp-config, creating users manually, or sharing admin credentials. BotCreds removes all of that. Install, click, copy, paste — your agent is connected in under a minute.

**Features:**

* One-click connection setup under Settings → BotCreds
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

1. Upload the `botcreds-agent-access` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Settings → BotCreds.
4. Click "Connect Agent" to generate your credentials.
5. Copy the credentials and paste them into your agent's config.

== Frequently Asked Questions ==

= What is an Application Password? =

Application Passwords are a built-in WordPress feature (since 5.6) that let external tools authenticate against the REST API without using your main account password. They can be revoked at any time without affecting your login.

= What agents and tools does this work with? =

Any tool that supports HTTP Basic Auth against the WordPress REST API. This includes Claude (via MCP), ChatGPT plugins, OpenClaw, n8n, Zapier, Make, custom Python scripts, and more.

= What is MCP? =

The Model Context Protocol (MCP) is an open standard for connecting AI agents to external tools and data sources. BotCreds makes it easy to connect an MCP client to your site.

= Is the password stored anywhere? =

The plain-text password is shown once when created. WordPress stores only a hash, so the password cannot be retrieved later. If you lose it, revoke the old one and create a new connection.

= Can I have multiple agent connections? =

BotCreds manages one Application Password per user. Revoke the existing one before creating a new connection, or create additional passwords directly in your WordPress profile.

== Screenshots ==

1. The "Not Connected" state with the one-click connect button.
2. The credentials display after creating a connection.
3. The "Connected" state showing status and revoke option.

== Changelog ==

= 2.2.6 =
* Add scope, content policy, and rate-limit editing for already-connected agents in admin profile view

= 2.2.5 =
* Fix: deactivation no longer removes the Agent role — role and data are preserved across deactivate/reactivate and upgrades; role removal now happens only on full uninstall

= 2.2.4 =
* Fix fatal error on WordPress < 5.8: missing function_exists guard for rest_get_authenticated_app_password() in scope class

= 2.2.3 =
* Add read-only scope template for agents that should never write

= 2.1.20 =
* New: Built-in **Agent** WordPress role. Create dedicated AI agent user accounts and assign them the Agent role — they appear with a clear Agent badge in the Connections dashboard. Default capabilities: publish posts/pages, upload media, manage categories. No access to site settings or user management. Capabilities can be further tuned with any role-management plugin.

= 2.1.19 =
* "View details" modal now shows full plugin description, installation steps, FAQ, and changelog — populated locally via plugins_api filter instead of a WordPress.org API call.

= 2.1.18 =
* Connections tab: role badges now show a tooltip on hover with a plain-English description of what that role can do (e.g. "Can publish and manage all posts, pages, categories, and comments."). Custom roles derive their tooltip from actual capabilities.

= 2.1.17 =
* Rebrand UI labels from "BotCreds" to "Agent Access" in Tools menu, settings page title, profile sections, and admin JS. Underlying credential name unchanged for backwards compatibility.

= 2.1.16 =
* Activity log now records write methods only (POST, PUT, PATCH, DELETE) by default. Read requests (GET/HEAD) are skipped. Add `add_filter( 'agent_access_log_reads', '__return_true' )` to re-enable read logging.

= 2.1.11 =
* Fix phpcs:ignore comment placement for uninstall_table() DROP TABLE — moved inline so Plugin Check actually picks it up.

= 2.1.10 =
* Fix Plugin Check warnings: add wp_unslash() to User-Agent read, add phpcs:ignore for schema-change DROP TABLE (NoCaching/NotPrepared false positives on constant table name), add PluginCheck.Security.DirectDB ignore on dynamic-but-prepared SQL in get_entries/count_entries.

= 2.1.9 =
* Security: Uninstall now correctly revokes credentials minted as 'BotCreds' (and all legacy names from prior rebrands). Previously uninstalling the plugin left live credentials on every connected account.
* Security: Activity log IP is now recorded from REMOTE_ADDR only — forwarded headers (CF-Connecting-IP, X-Forwarded-For) are client-controlled and were previously used, making the audit record forgeable.
* Security: Removed unverified Jetpack signature header as a source-detection signal — an empty header was sufficient to have any authenticated write attributed to the wordpress-mcp source.
* Fix: Admin page assets (admin.js / admin.css) were never loaded on the Tools page due to a hook suffix mismatch. Tools → BotCreds now renders correctly with all JS interactions working.
* Fix: Uninstall now drops the activity log table and version option (were missing).
* Fix: Windowed pagination for the activity log using core paginate_links() — prevents emitting thousands of page links on large sites.
* Fix: @mention notifications no longer fire for pending/unapproved comments, preventing notification spam before moderation.
* Fix: @mention regex now ignores email domains (foo@bar.com no longer extracts 'bar' as a mention).
* Fix: Removed unreachable render_created_state() and dead transient reads — credentials are delivered via AJAX/JS only.
* Improvement: Connections tab shows a warning when the site has more than 200 users (silent cap).
* Code: Extracted shared WHERE clause builder in the activity log class.
* Code: Renamed is_openclaw_request() to is_managed_agent_request(), get_all_openclaw_users() to get_connected_users().

= 2.1.0 =
* Added activity log for Agent Access app passwords and WP.com MCP connections.
* Activity Log tab in Tools → Agent Access — filterable by source and HTTP method, paginated.
* Removed ClawPress references — rebranded to BotCreds Agent Access.

= 2.0.3 =
* Fixed text domain mismatch in all includes (botcreds-agent-access).
* Trimmed tags to 5 for directory compliance.

= 2.0.2 =
* Renamed to BotCreds Agent Access.
* Updated text domain to botcreds-agent-access.

= 2.0.1 =
* Renamed to Botcred Application Passwords for clarity and directory compliance.
* Updated text domain to botcred-application-passwords.

= 2.0.0 =
* Rebranded and relaunched as BotCreds Agent Access.
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


