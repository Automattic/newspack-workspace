=== BotCreds Agent Access ===
Contributors: jboydston, droyal
Tags: ai-agents, mcp, application-passwords, rest-api, security
Requires at least: 5.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.4-rc.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scoped, per-agent application passwords for AI agents, MCP clients, and automation tools.

== Description ==

**BotCreds Agent Access** gives your AI agent, MCP client, or automation tool a secure, scoped credential to interact with your site — no code required.

Whether you're connecting Claude, ChatGPT, a custom MCP server, or an OpenClaw agent, BotCreds gives you profile controls that generate a properly scoped WordPress Application Password and logs recognized write activity by default.

**Connecting Claude, Codex, GrokBot or another agent**

This page is the setup reference. Your API is on **your publisher's website**, not wordpress.org or the demo site. Agent Access issues scoped WordPress credentials; it is **not an MCP server** and does not give a chat application HTTP tools. Use an agent with authenticated HTTP tools (for example a CLI agent with a terminal) or a compatible REST/MCP connector. Client capabilities vary; a subscription or model name alone does not establish connector support.

1. An administrator creates a dedicated account through **Users → Add Agent**, then opens that user's profile and clicks **Connect Agent** in Agent Access. Use a separate account per agent.
2. Select the needed scope and additional permissions, and save. The account's WordPress permissions still apply; a route allowance is not an Administrator role.
3. Copy the generated connection JSON and setup prompt into the agent's private credential/configuration flow. Never put passwords in public chats, screenshots, source control or support tickets. If a tool would forward credentials to another service, review that connector first.
4. Start with read-only connection checks. Do not use a content write as a login test.

**Copy/paste setup prompt**

> Connect to the publisher site in the credentials I supply privately. Save the application password in your private credential store, not source code. Use HTTPS HTTP Basic Auth with the WordPress username and application password, never my browser-login password. Do not print the Authorization header or password. First GET the site's WordPress REST users/me endpoint and the supplied connection_info_url. Report the authenticated identity, configured scope, dependencies and any permission blockers. Make no writes until I request them. Do not follow authentication redirects to another host. If authenticated HTTP tools are unavailable, tell me what connector or tool is missing. Do not guess that a successful login grants permission to edit content.

On Agent Access 2.4.4 or later, `GET /wp-json/agent-access/v1/connection` returns information about **only the managed application password used for that request**: scope mode, selected grants, account capabilities, dependencies, approval status, configured rate ceilings and endpoint URLs. It does not return passwords, hashes or other credentials. Browser sessions, manually created passwords and Pro tokens are not supported by this discovery endpoint. Older versions return a missing-route error; authentication can still work without discovery. Use the supplied URL when WordPress is installed in a subdirectory or uses `?rest_route=` URLs.

**Endpoint cookbook**

* Identity: `GET /wp-json/wp/v2/users/me`. A 200 proves authentication, not permission for another operation.
* Public content: `GET /wp-json/wp/v2/posts?per_page=1`. This may work anonymously, so it is not a login test. Edit-context reads require separate permissions.
* List/count accounts: with **List users** enabled, `GET /wp-json/wp/v2/users?context=view&per_page=100&page=1`. Read `X-WP-Total` and `X-WP-TotalPages`; paginate to retrieve all matches. This is not permission to create, edit or delete users.
* Yoast-only: with Yoast active and the SEO scope selected, `GET /wp-json/agent-access/v1/yoast-seo/POST_ID`; requested writes go to that same endpoint, for example `POST {"opengraph-title":"Social title"}`. Regular post writes remain blocked.
* Co-Authors Plus: with **Assign authors** enabled, first `GET /wp-json/agent-access/v1/authors/POST_ID/available?search=NAME`, then use returned canonical author IDs in a requested `POST {"authors":[ID,...]}` to `/wp-json/agent-access/v1/authors/POST_ID`. These are Co-Authors Plus IDs, not interchangeable WordPress user IDs. Requires already-editable content within the credential's scope. Assign authors last: handing ownership away can remove editing access.

**Connection troubleshooting**

* **401 / anonymous identity:** verify exact site, username and application password; use HTTPS. Check whether a proxy/firewall strips Authorization or redirects the request. Never send credentials to a redirected host automatically.
* **403 after users/me succeeds:** the password works. Record the JSON error code and route. `agent_scope_violation` means the requested write is outside the credential scope; `agent_credential_pending` needs administrator approval. Native WordPress permission errors may require account capabilities or ownership, not broader route patterns. Do not rotate a valid password merely because a write returns 403.
* **403 on connection discovery:** use the plugin-managed BotCreds password, not a browser cookie or a manually created password. This endpoint describes access; it cannot grant it.
* **404 / rest_no_route:** confirm plugin version, route, method and required dependency. Check the REST index on the publisher site. Do not assume a missing discovery endpoint means the credentials are invalid.
* **429:** respect any Retry-After information and back off; ask an administrator to review the rate tier if necessary. Do not retry in a tight loop.
* **HTML instead of JSON:** a login page, hosting firewall or security plugin may be answering before WordPress. Record the status and sanitized response, not credentials.
* **Working reads but no activity log:** reads are not logged by default. Managed writes appear in the log; manually created replacement passwords are not automatically managed. If a password is lost, revoke and regenerate it through Agent Access for the same user, then update the agent's private store.

Scope changes normally take effect on the next request with the same password; regeneration is not required. Existing prerelease SEO-only credentials may need an administrator to re-save the current scope. For support, provide plugin version, client/tool, sanitized route and method, HTTP status, JSON error code, and whether users/me succeeded. Never provide the password or Authorization header.

**Why BotCreds?**

Most AI agent setups require digging into wp-config, creating users manually, or sharing admin credentials. BotCreds removes all of that. Install, click, copy, paste — your agent is connected in under a minute.

**Features:**

* Connection setup on user profiles; dashboard under Tools → Agent Access
* Generates a secure, scoped Application Password for your AI agent or MCP client
* Works with any agent that supports the WordPress REST API: Claude, ChatGPT, OpenClaw, n8n, Zapier, custom MCP servers, and more
* User-level and site-level logging of agent actions — see exactly what your agent did and when
* Displays credentials in ready-to-paste format (table and JSON)
* Shows connection status, creation date, and last used date
* One-click revoke with confirmation
* Clean, modern admin UI using native WordPress styles
* Proper security: nonces, capability checks, password shown only once

**Compatibility:**

* Works with compatible REST/MCP connectors; does not include an MCP server
* Requires a client with authenticated HTTP tools or a compatible connector
* No third-party services or accounts required — everything stays on your site

== Installation ==

1. Upload the `botcreds-agent-access` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Create a dedicated account under Users → Add Agent, then open its profile.
4. In the Agent Access section, click "Connect Agent", choose the needed permissions and save.
5. Privately copy the generated setup prompt and credentials to your agent. View connections and activity under Tools → Agent Access.

== Frequently Asked Questions ==

= Can an agent list and count all site users without managing them? =

A signed-in administrator with user-management authority can enable **List users** on another user's Agent Access credential, under **Additional permissions**. It is off by default and works with Read only and Yoast SEO only. Requests use the native endpoint: `GET /wp-json/wp/v2/users?context=view&per_page=100&page=1`. Use `search` to filter; `X-WP-Total` and `X-WP-TotalPages` report matching totals and pages. With this grant, view-context requests include accounts without published posts. Edit context remains subject to WordPress’s separate profile-editing checks; use view context for a complete directory.

This exposes the site's account directory, including account names and native view-context profile fields, even for accounts without published posts. It does not grant private profile details for other accounts. It adds only `list_users` during the selected credential's users GET/HEAD request. It does not add account creation, profile editing, deletion, role/password changes, browser access, or access for other passwords. Existing permissions from other sources are unchanged. Disable the selection and Save settings to revoke it immediately without regenerating the password.


= What is an Application Password? =

Application Passwords are a built-in WordPress feature (since 5.6) that let external tools authenticate against the REST API without using your main account password. They can be revoked at any time without affecting your login.

= What agents and tools does this work with? =

Any tool that supports HTTP Basic Auth against the WordPress REST API. This includes Claude (via MCP), ChatGPT plugins, OpenClaw, n8n, Zapier, Make, custom Python scripts, and more.

= What is MCP? =

The Model Context Protocol (MCP) is an open standard for connecting AI agents to external tools and data sources. BotCreds makes it easy to connect an MCP client to your site.

= Is the password stored anywhere? =

The plain-text password is shown once when created. WordPress stores only a hash, so the password cannot be retrieved later. If you lose it, revoke the old one and create a new connection.

= Can I have multiple agent connections? =

BotCreds manages one Application Password per WordPress user. For multiple agents, create a separate Agent user for each. If you lose a password, use Agent Access to revoke the existing connection and generate replacement credentials for the same user.

= Where did the native Application Passwords controls go? =

While Agent Access is active, the native Application Passwords section is hidden on your profile and on profiles edited by administrators. Use the Agent Access controls to generate and revoke managed credentials. Passwords created through other tools are not automatically managed or logged by Agent Access.

This is a profile UI change only: existing Application Passwords and WordPress REST authentication continue to work. Deactivating Agent Access restores the native profile controls.

= Which requests appear in the activity log? =

By default, the log records write requests from recognized credentials, not reads. A successful read alone does not create an activity entry.

= What does Yoast SEO only permit? =

A separate signed-in administrator can assign this scope to an agent credential. With Yoast SEO active, the agent may edit SEO titles, descriptions, focus keyphrases and social titles/descriptions on existing content across all authors. It cannot update post content, headlines, publication status, taxonomies or other settings.

Clients must use `GET`, `POST`, `PUT` or `PATCH` on `/wp-json/agent-access/v1/yoast-seo/{id}`. Writes accept a JSON object containing supported Yoast fields, for example `{"opengraph-title":"Social title"}`. The regular post-update endpoint remains blocked. This does not grant general WordPress editing capabilities. Existing prerelease SEO credentials require an administrator to save the scope again; Application Password regeneration is not required.

= Does allowing plugin integrations give agents Administrator access? =

No. Integration scopes permit routes, not WordPress capabilities. The account's existing permissions still apply. The separate Site management control grants settings and appearance capabilities to an Agent account only when an authorized administrator enables it; it does not grant user management or plugin/theme installation.

== Screenshots ==

1. The "Not Connected" state with the one-click connect button.
2. The credentials display after creating a connection.
3. The "Connected" state showing status and revoke option.

== Changelog ==

= 2.4.4-rc.1 =
* New: Read-only discovery of the currently authenticated managed credential at agent-access/v1/connection.
* Improved: Shared agent setup guide, safe connection prompt, endpoint recipes and permission troubleshooting.


= 2.4.3 =
* New: Administrator-approved List users permission for an individual managed credential. Lists, searches and counts site accounts through the native users REST endpoint without granting user writes.
* UI: Group List users, author assignment and site management under collapsed Additional permissions, with contextual help and explicit directory/account-wide disclosures.


= 2.4.2 =
* New: Administrator-enabled author assignment for Co-Authors Plus through a dedicated byline endpoint. Supports users, guest authors and ordered bylines on existing editable content.
* New: Permission-gated author lookup and strict rejection of mixed content/status updates. No broad editing capabilities are added.
* Fix: Preserve an explicit full-access wildcard when saving credential scopes.


= 2.4.1 =
* Hide native Application Passwords controls on profile pages while Agent Access is active, keeping credential creation in the managed flow without disabling authentication.
* Clarify replacement credentials, separate users for multiple agents, and write-only activity logging.

= 2.4.0 =
* Add an administrator-assigned Yoast SEO-only credential scope for existing content across all authors. The dedicated metadata endpoint blocks content edits, publishing, creation, deletion and unrelated writes.
* Add friendly plugin-integration scopes, an allow-all integrations option, and advanced custom REST route prefixes without widening core content access.
* Add explicit per-Agent site-management access for settings, appearance and menus, with administrator-only assignment and reversible, site-specific grants.
* Resolve authorization from the verified BotCreds credential before client-controlled MCP attribution, preserving scope, rate-limit and content-policy enforcement.
* Harden credential-management authorization and report effective capabilities in authorized REST user responses.
* Improve settings layout, accessible contextual help, scope summaries and persistent save/unsaved/error feedback.
* Remove lobster icons and stop displaying unused WordPress account passwords when creating agents.
* Preserve restrictive prerelease SEO grants until an administrator explicitly re-saves the SEO-only selection.

= 2.3.4 =
* Scope UI: Agent Memory integration detection uses class_exists() instead of is_plugin_active() — works regardless of plugin folder name

= 2.3.3 =
* Scope: literal REST route patterns (starting with /) now pass through as valid allowed routes, enabling custom namespace support (e.g. /botcreds-memory/v1/mcp)
* Scope UI: Agent Memory integration checkbox (auto-detected when BotCreds Agent Memory is active)
* Scope: collect_scope_from_post() preserves literal route patterns instead of stripping slashes via sanitize_key()

= 2.3.2 =
* Fix: content policy now hooks all public REST-enabled CPTs, not just core post/attachment — custom post types like newspack_nl_cpt were completely ungoverned

= 2.3.1 =
* Fix: content policy now blocks publish on status-promotion updates, not just on create
* Fix: content_policy POST key mismatch between JS and PHP in admin update handler

= 2.3.0 =
* Scope redesign: replace single dropdown with per-CPT checklist. Automatically discovers all REST-enabled post types on the site. Legacy scope keys migrated automatically.

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


== Upgrade Notice ==

= 2.4.3 =
Adds administrator-approved user listing and counts without user-management access. Advanced permissions are grouped under Additional permissions. Existing credentials keep their current access; List users is off until explicitly enabled.

= 2.4.1 =
Native Application Passwords controls are hidden while Agent Access is active. Use Agent Access to generate or replace managed credentials. Existing passwords continue to work.

= 2.4.0 =
Adds SEO-only access, integration scopes and site-management controls. Fixes header-dependent credential scope enforcement. Prerelease SEO users: an administrator must re-save the SEO-only scope; existing passwords remain valid.
