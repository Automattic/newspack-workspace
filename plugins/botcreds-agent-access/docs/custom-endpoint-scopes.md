# Custom endpoint scopes

The credential's scope is an additional write restriction, not a replacement for WordPress capabilities or each endpoint's permission callback. GET, HEAD and OPTIONS retain the existing behavior and are not restricted by this scope gate. Human cookie sessions and unrelated application passwords are unchanged.

## Settings

The shared picker appears in self-service credential creation, administrator creation and administrator updates:

- Read only: no writes.
- Full write access: the existing `*` grant; both core and custom routes, still subject to user permissions.
- WordPress content: existing post-type grants and associated categories/tags.
- Detected integrations: registered non-core REST namespaces. Known integrations get friendly labels; others derive a label from their namespace. Child namespaces already covered by a listed parent are not repeated. Nothing is granted automatically.
- Allow all custom endpoints: the `__custom_endpoints__` scope entry grants registered non-core namespaces, including future integrations. It does not grant core routes or taxonomy writes by itself.
- Advanced: custom route prefixes, one per line. Prefixes include descendants; a trailing `/*` is optional. URLs, dot segments, interior wildcards, encoded paths and core namespace patterns are rejected, not transformed. Inactive integration patterns remain visible here.

All integration grants permit all write methods, including DELETE. This change does **not** add per-method restrictions or a scope-management REST API. Existing rate limits, content policies, approval gates and endpoint permission callbacks still apply.

Core namespace prefixes `wp/` and `wp-`, plus oEmbed, are reserved. Plugin endpoints registered inside the core `wp/v2` namespace must use the content scope or an explicit full grant; they are not blanket-granted as custom endpoints.

Saving an empty selection now stores read-only instead of unrestricted access. Legacy full grants are shown explicitly, and temporarily inactive post-type grants are retained in the picker. Invalid scope input is rejected before credential creation or settings mutation. Existing stored route-prefix enforcement remains supported; this UI accepts custom, not core, prefixes.

## Verification

`tests/custom-scopes-wordpress.php` is a hosted WP-CLI regression runner. Run it on a dedicated QA site with the candidate active; it registers in-process synthetic routes and creates/deletes a disposable user. It covers scope boundaries, discovery, strict validation, persistence, legacy migration and nonce-protected admin updates including denied self-escalation. Do not run on a production site.

`tests/custom-scopes-browser.cjs` uses actual PHP-rendered controls in a local fixture with production `assets/js/admin.js`. It checks browser modes, disabled fieldsets, serialized save payloads and narrow layouts. Its AJAX response is mocked; it is not authenticated wp-admin end-to-end coverage. Configure `AA_PLAYWRIGHT_MODULE` if playwright-core is not locally resolvable. It connects to an existing local CDP browser on port 18800 and creates a disposable context.

The release QA report separately records real HTTPS application-password tests against temporary side-effect-free routes. Those prove authentication and scope enforcement, not actual Workflows or WooCommerce business operations.

`build.sh` packages distribution files only, supports release-candidate version strings, and excludes tests, docs and repository metadata.
