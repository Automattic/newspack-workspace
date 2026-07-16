# Security guidance for the Newspack monorepo

WordPress plugins/themes for publisher news sites. Crown jewels: **reader identity,
subscriber PII, paywall integrity, ESP/OAuth credentials**.

## Do not re-flag what the linter already catches

A single root `phpcs.xml` applies `WordPress-Extra` + `WordPress-Docs` +
`WordPress-VIP-Go` across the repo and blocks commits via a husky hook (warnings block
for PHP). Assume these are enforced; do **not** report them as findings:

- `WordPress.Security.EscapeOutput`, `.NonceVerification`, `.ValidatedSanitizedInput`,
  `.SafeRedirect`
- `WordPress.DB.PreparedSQL*`, `.DirectDatabaseQuery`, `.SlowDBQuery` – the repo
  deliberately suppresses `NoCaching` on nearly every raw query; never flag missing
  caching
- `WordPress.WP.Capabilities`, `WordPress.WP.AlternativeFunctions`, `WordPressVIPMinimum.*`

Report these only when the sniff is **suppressed** and the suppression is wrong. A
`phpcs:ignore` is where the machine stopped and a human assumption started – a *new* one
on a security sniff needs explicit justification.

Your value is in what PHPCS cannot see: **authorization logic, IDOR, HMAC/replay
correctness, paywall bypass, cache leakage, secret exposure.**

## Authorization

- **A nonce is not an authorization check.** `check_ajax_referer` proves the request came
  from a page, not that the caller may perform the action. Every `wp_ajax_` handler needs
  a capability check too – beside the nonce, or provably inside the domain method it
  calls (`Content_Gifting::generate_key()` checks `can_gift_post()`). Trace the callee
  before concluding it's fine; flag when neither layer has one.
- **IDOR:** any handler taking `$_POST['user_id']`, `post_id`, `order_id` etc. and
  writing must verify the current user may act on *that object* – not merely that they
  are logged in. `(int)` casting is not authorization.
- **REST:** the convention is a per-class `api_permissions_check` (`current_user_can(
  $this->capability )`, default `manage_options`) returning a 403 `WP_Error`. Newsletters
  splits admin (`manage_options`) vs authoring (`edit_others_posts`). A new route must
  not silently widen this.
- **`permission_callback => '__return_true'` is legitimate here**, in three shapes; check
  the diff stays inside one:
  1. *Signature-verified machine endpoints* (network webhook/pull) – auth is a
     shared-secret check inside the callback.
  2. *Public frontend reads* – stay read-only, expose nothing per-reader.
  3. *Reader-facing writes* – enforce identity in the body (my-account checks
     `(int) $user_id === get_current_user_id()`).
  A `__return_true` route with no in-callback auth is a finding. These routes must also
  declare `args` with a `sanitize_callback`.

## Content gate / paywall bypass

`newspack-plugin/includes/content-gate/` decides who reads paid content. Bypass logic is
HMAC-based; preserve these invariants:

- Sign with `hash_hmac('sha256', …, wp_salt(...))`; compare **only** with `hash_equals`.
- **Validate cheaply before touching the DB**: cap token length before base64-decode,
  `ctype_digit` the id/expiry, verify the HMAC, and only then query. Reordering this so a
  forged token costs DB work reintroduces a DoS.
- TTL/expiry must be derived server-side (e.g. post meta), never from a client timestamp.
- Bypass grants must be scoped to the specific post, so a grant for one post cannot
  unlock another.

## Caching and personalized content

Batcache (`bin/advanced-cache.php`) serves a shared, anonymous page cache. Its key
contains **no reader identity**, so exemption is the only thing stopping one reader's
gated content reaching another. Highest-consequence area in the repo.

- Any cookie granting access or personalizing a response **must be named with the `wp`
  prefix** – that prefix is what makes Batcache skip the cache. Renaming such a cookie
  without it silently poisons the cache. See the `Newsletters_Access::COOKIE_NAME`
  docblock.
- Any path emitting personalized/gated output must call **both** `batcache_cancel()` and
  `nocache_headers()`.
- Access cookies must be HMAC-signed with an expiry, not bare flags.
- Do not introduce `vary_cache_on_function` – the strategy is cookie-prefix exemption,
  not cache variants (that path `eval()`s its argument).

## Secrets

ESP/OAuth credentials (Mailchimp/Constant Contact/ActiveCampaign keys, Salesforce tokens,
`newspack_node_secret_key`) are stored **unencrypted in wp_options**, so exposure control
is entirely about egress:

- Never add a credential to a REST response, log line, error message, or JS payload.
  Newsletters gates this with an explicit `PROVIDER_CREDENTIAL_ALLOWLIST`; widening it,
  or returning a secret not on it, is a finding.
- Refresh/access tokens must never leave the server.
- Fail **closed** on an empty signing secret – an empty-key HMAC is reproducible by
  anyone (see `Salesforce::api_validate_webhook`).

## Network hub/node sync

**Trust model – read before reporting anything in `newspack-network`.** The model assumes
**every Node is operated by the same operator** (see the `User_Manually_Synced` docblock).
Auth is symmetric AEAD: a successful decrypt proves possession of the shared key, and that
is the entire authorization. A compromised node is therefore **accepted risk, out of
scope** – an attacker holding a node's key is a bigger incident than the sync layer can
defend against. Attacker-controlled payloads are a premise here, not a finding.

**Not findings** – by design:

- A node broadcasting user-sync events that create accounts on the hub and other nodes.
  `Reader_Registered` calls `get_or_create_user_by_email()` on both deliberately;
  propagating accounts across the network *is* the feature.
- **Roles propagating network-wide, including `administrator`.** The manual "Sync user
  across network" button sends `role` on the wire and `User_Manually_Synced` applies it
  unallowlisted by design – that is how an admin on one site propagates an admin role.
- No second auth factor behind the signature check; no replay cache beyond the 60s window.

Scoped to the same-operator model: if a diff *changes* that model (e.g. admitting
third-party nodes), the constraints that docblock names – `sanitize_user()` on
`user_login`, restricting `role` – become real requirements.

## Injection the linter misses

- **Inline `<script>` JSON:** `wp_json_encode()` does not escape `<`, `>`, or `/`, so an
  encoded value containing `</script>` breaks out. When echoing JSON into a literal
  script block, pass `JSON_HEX_TAG | JSON_HEX_AMP`, or prefer `wp_localize_script`.
- **Block `render_callback`s** (`src/blocks/*/view.php`) build HTML/shortcodes with
  `sprintf`/concatenation. Escape at output (`esc_attr` on `className`) and validate
  attributes into a local var – `block.json` type coercion is not a security boundary.
  Blocks delegating to a template must be audited at the template.
- **Frontend JS:** `.innerHTML =` with server or reader-supplied values (content-gate
  banners, popups merge tags rendering reader profile data) needs the value sanitized
  server-side (`wp_kses`) or set via `textContent`.

## Reader identity

The public `/register` endpoint's "integration key" is `hash_hmac(...)` of a static id and
is **shipped to the frontend** – bot friction, not authentication. Never treat it as proof
of identity. The honeypot inverts the fields: the real address arrives in `npe`, while
`email` must be empty.

## CI workflows

- `pull_request_target` jobs run privileged against an attacker-controlled tree, which is
  **DATA ONLY** – never install, build, or run anything from it (that hands over the
  PAT). See the SECURITY INVARIANT block in `dependabot-sync-overrides.yml`.
- Untrusted `${{ github.event.* }}` reaches `run:` only via `env:` indirection, never
  interpolated into the script body.
- Gate on `github.event.pull_request.user.login`, not `github.actor`.
- Pin third-party actions by full SHA + version comment; PAT use needs a written
  justification.
