# Security guidance for the Newspack monorepo

WordPress plugins/themes for publisher news sites. Crown jewels: **reader identity,
subscriber PII, paywall integrity, ESP/OAuth credentials**.

## Do not re-flag what the linter already catches

A single root `phpcs.xml` runs `WordPress-Extra` + `WordPress-Docs` + `WordPress-VIP-Go`
over `plugins/`, `themes/`, `packages/` only – `bin/` (incl. `bin/advanced-cache.php`),
`config/`, and `repos/` are **never sniffed**. Its sniffs are also syntactic heuristics
(they pass on wrong-context escaping, the wrong variable, a custom sanitizer), and the
pre-commit hook is staged-only and bypassable. So **deprioritize** these; report one only
with a concrete bypass, an unlinted path, or a wrong-context escape:

- `WordPress.Security.EscapeOutput`, `.NonceVerification`, `.ValidatedSanitizedInput`,
  `.SafeRedirect`
- `WordPress.DB.PreparedSQL*`, `.DirectDatabaseQuery`, `.SlowDBQuery` – the repo
  deliberately suppresses `NoCaching` on nearly every raw query; never flag missing caching
- `WordPress.WP.Capabilities`, `WordPress.WP.AlternativeFunctions`, `WordPressVIPMinimum.*`

A *new* `phpcs:ignore` on a security sniff needs explicit justification. Your value is what
PHPCS cannot see: **authorization logic, IDOR, HMAC/replay correctness, paywall bypass,
cache leakage, secret exposure.**

## Authorization

- **A nonce is not an authorization check.** `check_ajax_referer` proves the request came
  from a page, not that the caller may act. Every `wp_ajax_` handler needs a capability
  check too – beside the nonce, or provably inside the domain method it calls. Trace the
  callee; flag when neither layer has one.
- **IDOR:** any handler taking `$_POST['user_id']`, `post_id`, `order_id` etc. and writing
  must verify the user may act on *that object* – not merely that they're logged in.
  `(int)` casting is not authorization.
- **REST:** convention is a per-class `api_permissions_check` (`current_user_can(
  $this->capability )`, default `manage_options`) returning a 403 `WP_Error`. Newsletters
  splits admin (`manage_options`) vs authoring (`edit_others_posts`); a new route must not
  silently widen this.
- **`permission_callback => '__return_true'` is legitimate here**, in three shapes; check
  the diff stays inside one:
  1. *Signature-verified machine endpoints* (network webhook/pull) – shared-secret check
     inside the callback.
  2. *Public frontend reads* – read-only, expose nothing per-reader.
  3. *Reader-facing writes* – enforce identity in the body (my-account checks
     `(int) $user_id === get_current_user_id()`).
  A `__return_true` route with no in-callback auth is a finding. These routes must also
  declare `args` with a `sanitize_callback`.
- The public `/register` endpoint's "integration key" is shipped to the frontend: bot
  friction, not authentication; never proof of identity.

## Content gate / paywall bypass

`newspack-plugin/includes/content-gate/` decides who reads paid content; bypass logic is
HMAC-based. Invariants:

- Sign with `hash_hmac('sha256', …, wp_salt(...))`; compare **only** with `hash_equals`.
- **Validate cheaply before the DB**: cap token length before base64-decode, `ctype_digit`
  the id/expiry, verify the HMAC, then query. Reordering so a forged token costs DB work
  reintroduces a DoS.
- TTL/expiry must be derived server-side (e.g. post meta), never a client timestamp.
- Bypass grants must be scoped to the specific post, so one post's grant can't unlock
  another.

## Caching and personalized content

Batcache (`bin/advanced-cache.php`) serves a shared, anonymous page cache. Its key contains
**no reader identity**, so exemption alone stops one reader's gated content reaching
another. Highest-consequence area in the repo.

- Any cookie granting access or personalizing a response **must be named with the `wp`
  prefix** – that is what makes Batcache skip the cache; renaming such a cookie without it
  silently poisons it. See the `Newsletters_Access::COOKIE_NAME` docblock.
- Any path emitting personalized/gated output calls **both** `batcache_cancel()` and
  `nocache_headers()`.
- Access cookies must be HMAC-signed with an expiry, not bare flags.
- Do not introduce `vary_cache_on_function` – the strategy is cookie-prefix exemption, not
  cache variants (it `eval()`s its argument).

## Secrets

ESP/OAuth credentials (Mailchimp/Constant Contact/ActiveCampaign keys, Salesforce tokens,
`newspack_node_secret_key`) are stored **unencrypted in wp_options**; exposure control is
all egress:

- Never add a credential to a REST response, log line, error message, or JS payload.
  Newsletters gates this with `PROVIDER_CREDENTIAL_ALLOWLIST`; widening it, or returning a
  secret not on it, is a finding.
- Refresh/access tokens must never leave the server.
- Fail **closed** on an empty signing secret – an empty-key HMAC is reproducible by anyone
  (see `Salesforce::api_validate_webhook`).

## Network hub/node sync

**Trust model – read before reporting anything in `newspack-network`.** The model assumes
**every Node is operated by the same operator** (see the `User_Manually_Synced` docblock).
Auth is symmetric AEAD: a successful decrypt proves possession of the shared key, the
entire authorization. A compromised node is therefore **accepted risk, out of
scope**. But that excuses only *who* sent the bytes, never *what they do at the sink*: a
payload field reaching SQL, `unserialize()`, a file path, an HTML/JS sink, or a server-side
fetch stays in scope (e.g. `class-incoming-post.php` sideloads payload `thumbnail_url` via
`media_sideload_image()`).

**Not findings** – by design:

- A node broadcasting user-sync events that create accounts on the hub and other nodes.
  `Reader_Registered` calls `get_or_create_user_by_email()` on both deliberately;
  propagating accounts network-wide *is* the feature.
- **Roles propagating network-wide, including `administrator`.** The manual "Sync user
  across network" button sends `role` and `User_Manually_Synced` applies it unallowlisted
  by design – how an admin propagates an admin role.
- No second auth factor behind the signature check; no replay cache beyond the 60s window.

If a diff *changes* the same-operator model (e.g. admits third-party nodes), the
constraints that docblock names – `sanitize_user()` on `user_login`, restricting `role` –
become real requirements.

## CI workflows

- `pull_request_target` jobs run privileged against an attacker-controlled tree, which is
  **DATA ONLY** – never install, build, or run anything from it (that hands over the PAT).
  See the SECURITY INVARIANT block in `dependabot-sync-overrides.yml`.
- Untrusted `${{ github.event.* }}` reaches `run:` only via `env:` indirection, never
  interpolated into the script.
- Gate on `github.event.pull_request.user.login`, not `github.actor`.
- Pin third-party actions by full SHA + version comment; PAT use needs written
  justification.

## Injection the linter misses

- **Inline `<script>` JSON is not a `</script>` breakout – do not report one.**
  `json_encode` escapes `/` as `\/` by default, so an encoded `</script>` emits `<\/script>`
  and cannot close the element. Bare `echo wp_json_encode( $x )` in a script block is fine;
  `JSON_HEX_TAG` fixes nothing there. `<!--<script>` does derail the tokenizer, but for
  **post content** it needs `unfiltered_html` to store (Editors/Admins, who can inject raw
  `<script>` anyway) – *not* so for reader-sourced data (profile fields, content-gate
  values), where `<!--<script>` in an inline JSON blob is a real finding; give it
  `JSON_HEX_TAG`.
- **Block `render_callback`s** (`src/blocks/*/view.php`) build HTML/shortcodes with
  `sprintf`/concatenation. Escape at output (`esc_attr` on `className`) and validate
  attributes into a local var – `block.json` types are not a security boundary.
  Template-delegating blocks are audited at the template.
- **Frontend JS:** `.innerHTML =` with server or reader-supplied values (content-gate
  banners, popups merge tags rendering reader profile data) needs the value `wp_kses`'d
  server-side or set via `textContent`.
