# Per-agent Site management

An administrator can enable **Site management** while creating or updating a BotCreds connection on a dedicated Agent user's profile. The switch is off by default and grants exactly `manage_options` and `edit_theme_options`. It is **account-level**, not per credential: all sessions and credentials for that user on that site get these capabilities. The user keeps the Agent role. Authorized REST user responses that already include a capabilities field report the two effective grants, so API clients can discover them without exposing capabilities in public responses. Endpoint scope, rate limits, content policy, approval controls and endpoint-native permissions still apply.

## Authorization

The switch is visible/writable only to another signed-in user with a valid logged-in cookie, `manage_options`, `promote_users` and target-specific `edit_user` authority. It is unavailable to the target itself and to Application Password/Pro Auth requests, even if the actor is an administrator. Only dedicated users with the Agent role can be targeted. The existing AJAX nonce is checked before parsing the decision, and all grant/scope input is validated before any settings are saved or a credential is created.

A missing field preserves the grant for older clients and forms that cannot show the switch. Only exact `0` or `1` values are accepted. Self-service creation explicitly rejects the grant field. Admin credential creation/revocation/update and Pro Auth setup now additionally check target-user authority; Pro token revocation requires user-management authority. Granting `manage_options` alone must not let an agent obtain credentials for an administrator.

## Storage and lifecycle

The grant is stored as `_agent_access_site_management_{blog_id}=1` in user metadata. The shared Agent role and stored user capabilities are never modified. A `user_has_cap` filter adds only the two capabilities for that Agent user on the matching site.

Turning the switch off deletes the plugin's metadata; capabilities supplied by other roles or direct user grants remain untouched. Removing/changing away from the Agent role clears the grant. Deactivating the plugin stops the runtime filter, leaving no added persistent capabilities. Reactivation retains an explicitly saved grant unless it was revoked or the role changed. Uninstall removes grant metadata across sites. Multisite isolation was tested by metadata-key separation, not a complete multisite-network deployment.

This is intentionally not an Administrator role assignment. It grants neither user management nor plugin/theme installation. These two capabilities are nevertheless broad: installed plugins may protect powerful operations with them. Scopes restrict writes only; this change does not add read scopes or method-specific integration grants.

## QA

Run `tests/site-management-wordpress.php` using WP-CLI on a disposable QA site with the candidate active. The runner creates/deletes synthetic admin/agent/editor identities and authenticates its in-process admin callback tests using WordPress-generated fixture cookies and nonces. It verifies grant/revoke, capability ownership, self/cross-user escalation denial, credential-takeover guards, validation-before-mutation, creation/update forms and role/deactivation lifecycle behavior. Runtime-filter removal is an in-process deactivation boundary check, not a production deactivation test; uninstall was source-reviewed rather than run against a populated site.

Release QA separately exercises real HTTPS administrator-cookie saves and denials, Application Password requests to a temporary harmless workflow probe requiring both capabilities, and real WordPress settings reads before/after revocation. No actual workflow, menu or setting is modified. UI checks use actual PHP-rendered controls plus production JavaScript with a mocked save response and inspect the outgoing request payloads. The browser harness supports isolated Chrome via `AA_HEADLESS=1` and `AA_CHROME_PATH`, or a local CDP browser by default. Tests and docs are excluded from the installable ZIP.
