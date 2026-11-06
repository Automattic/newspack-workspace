# Yoast SEO-only credentials — 2.4.0-rc.10

A separate human administrator opens the target user's Agent Access settings, selects **Yoast SEO only**, then saves. This allows the dedicated SEO controller to edit existing REST-enabled content across all authors. No Site management grant, Administrator role or edit_others_posts capability is added to the agent. Reads/writes through other routes remain governed by their own rules and this restrictive write scope.

Only an authenticated human-admin cookie session with manage_options, promote_users and edit_user(target) can assign it. Self-assignment, Agent-role actors (even with administrative capabilities), Pro tokens, Application Passwords and invalid cookies/nonces are denied. Self-service forms omit the control; forged scope flags and special scope markers in scope_types are rejected before creating/updating credentials.

**Upgrade action:** rc.9's self-selectable `__yoast_seo_only__` marker is preserved as a restrictive legacy grant with native edit_post checks. It is never auto-promoted. An administrator must save the single Yoast SEO-only selection again to issue `__yoast_seo_all__`. Existing Application Passwords need not be regenerated. There is no second SEO-access checkbox.

This is an exclusive write mode, not an additive integration checkbox. Precedence is Read only → Yoast SEO only → Full → selected grants. Broader grants cannot override SEO-only mode, even in malformed/legacy mixed stored arrays. Other credentials and ordinary human sessions retain their own permissions. The cross-author exception is bound to the authenticated credential’s explicit scope, never to the whole account or shared role. Reads keep the existing native WordPress permission boundary.

## Agent API

Existing REST-enabled content only:

`GET /wp-json/agent-access/v1/yoast-seo/{id}`

`POST`, `PUT` or `PATCH` to that same URL, authenticated with the agent's Application Password (or its existing Pro token), with `Content-Type: application/json`:

```json
{"title":"SEO title","metadesc":"Search description","focuskw":"focus keyphrase"}
```

Additional accepted keys: `opengraph-title`, `opengraph-description`, `twitter-title`, `twitter-description`. Values must be strings of at most 10,000 bytes. Omitted fields are unchanged; empty strings clear the saved override. Text is sanitized; Yoast template variables, quotes and backslashes are supported. Responses contain the post ID and a `yoast` object with the seven current values.

**The `title` key here is the Yoast SEO title, not the post headline.** The ordinary `/wp/v2/posts/{id}` update endpoint remains blocked, even for metadata-only updates. Existing clients must use the dedicated SEO endpoint in this mode. A per-post endpoint makes the field boundary explicit instead of granting the generic post controller and trying to suppress its other side effects.

No post creation, content/title/slug/status updates, publishing, deletion, revisions/autosaves, taxonomy changes, generic metadata, settings, robots/canonical settings, Yoast-wide settings, other integrations or batch writes. Every write request is validated in full before any field is saved; mixed requests fail rather than silently discarding forbidden fields. Query/form/file payloads are rejected. The id comes only from the matched URL. Inactive Yoast fails closed (503); the restrictive scope remains in force. Existing trash, auto-drafts, revisions and attachments are not writable through this endpoint.

Storage uses Yoast's `WPSEO_Meta::set_value` / `get_value`, not `wp_update_post` or a generic metadata endpoint. Yoast's own metadata watcher rebuilds the indexable. The API verifies each saved field and returns a 500 with a read-back/retry instruction if storage fails. Validation rejection is all-or-nothing; multi-field storage failures are not claimed to be transactional.

## Verification boundary

WordPress 7.1.4 / PHP 8.4.27 / official Yoast SEO 28.8 on the dedicated disposable QA site:

- 141 hosted SEO-scope and durable-data checks using the real REST dispatcher, synthetic process-local Pro identity, real capabilities and Yoast storage. A further 14 hosted administrator-assignment checks verify cookie/provenance requirements, privileged-Agent denial, special-marker rejection and legacy notice visibility. Includes mixed broad scopes, payload type/pollution attacks, atomic validation rejection, template/quote/backslash sanitization and idempotence, nonexistent post rejection, anonymous denial, persistence and unchanged post columns.
- 61 existing site-management + 93 existing scope assertions.
- 40 real HTTPS assertions using actual Agent Access Application Passwords and an administrator cookie: successful POST/PUT/PATCH, independent read-back, forbidden generic post/batch/settings writes, ungranted subscriber denial, anonymous denial, real admin scope save with malicious broad flags, and Yoast's public `get_head` output reflects new title/description. Cross-author fixture: the published post belongs to the human administrator, not the Agent. Verifies rc.9 denial before explicit approval, agent/self-service denial, invalid nonce, successful admin approval, unchanged WordPress capability flags, Social Title write, revocation and restored permission. All post columns remain unchanged after these requests.
- 2 real HTTPS inactive-Yoast fail-closed assertions.
- 40 browser scope/payload checks, including SEO-only priority/disabled controls/serialization, plus 53 tooltip/design regressions. Actual hosted PHP rendering and production JS/core styles; mocked AJAX transport. Not live wp-admin browser end-to-end claims. The renderer registers one synthetic Workflows namespace process-locally for existing UI regression fixtures; it is not installed on the live HTTP surface.
- 21 native PHP files lint; Plugin Check 0 errors/21 existing warnings, no new signatures vs original 2.3.2. No other Yoast versions or Premium-specific fields claimed tested.

Dedicated QA restored to original byte-identical Agent Access 2.3.2. Temporary users/posts/credentials/sessions/logs and Yoast activation options/cron/tables cleaned; active-plugin and table inventories unchanged. No publisher staging install, Git push or SVN publication.
