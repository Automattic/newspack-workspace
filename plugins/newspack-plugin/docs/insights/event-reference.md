# Event Reference

Canonical reference for the Newspack GA4 custom events that Insights queries from the BigQuery export.

**Provenance.** The canonical spec is the public help doc at <https://help.newspackstaging.com/analytics-performance/newspack-custom-events/>. This file mirrors that content with three layers of additions:

1. **Updates not in the public help doc** — known schema deltas captured in the [next section](#updates-not-in-the-public-help-doc). Source: <https://newspack.com/google-analytics-custom-events-upcoming-changes/> announcement.
2. **Observed-but-undocumented params and coverage stats** — pulled from BIGQUERY.md in the gate-intelligence repo (analyst-facing event catalog generated against a 30-day sample of the test property `customer-analytics-293121.analytics_400107557`). These are flagged inline with provenance comments. Note that the sample is from one publisher's test property and coverage statistics may not generalize across all Newspack publishers.
3. **Code-verified parameters and dimensions** — pulled directly from newspack-plugin source (`includes/plugins/google-site-kit/class-googlesitekit.php` and `includes/plugins/google-site-kit/class-ga4-custom-dimensions.php`). Flagged inline.

When the help doc, BIGQUERY.md, and code disagree, code wins. When BIGQUERY.md surfaces a parameter the code doesn't emit, treat as historical or sample-specific and flag the gap.

---

## Updates not in the public help doc

Four known deltas between the live event schema and the published help doc. Formulas should rely on the corrected spec below, not the help doc text alone.

### 1. `gate_has_registration_link` and `gate_has_signin_link` exist on `np_gate_interaction`

The help doc lists `gate_has_donation_block`, `gate_has_registration_block`, and `gate_has_checkout_button` but **omits** `gate_has_registration_link` and `gate_has_signin_link`. Both are emitted on every `np_gate_interaction` event (~100% coverage per BIGQUERY.md sample) as string `"yes"` / `"no"`. They fire when the gate shows a link to open a registration or signin modal (vs. an embedded block). Both are also provisioned as standard custom dimensions per `class-ga4-custom-dimensions.php`.

Source: <https://newspack.com/google-analytics-custom-events-upcoming-changes/> explicitly lists them in the overhaul announcement.

**Material impact:** the Regwall Conversion Rate denominator (gate impressions that offered registration) needs **both** `gate_has_registration_block` and `gate_has_registration_link` — a gate can offer registration via either an embedded block or an inline link.

### 2. `action_type=paid_membership` was renamed to `action_type=checkout_button`

The overhaul announcement deprecated the `paid_membership` action_type value and replaced it with `checkout_button` across both `np_gate_interaction` and `np_modal_checkout_interaction`. The help doc reflects the new value; historical data may contain the old one. Formulas targeting current data use `checkout_button`; if joining against historical data, accept either via `IN ('checkout_button', 'paid_membership')`.

### 3. `form_submission_success` on `np_modal_checkout_interaction` is deprecated

Historically, `action='form_submission_success'` on `np_modal_checkout_interaction` indicated a completed paid checkout. This value is **deprecated** per the overhaul announcement and should no longer be relied on for completion attribution.

**New pattern:** to track conversion success starting from `np_gate_interaction` events, match `gate_post_id` across the downstream completion events:

- `np_reader_registered` — for registration completion
- `np_reader_logged_in` — for login completion
- `np_modal_checkout_interaction` — for checkout intent and modal steps

For **paywall completion** specifically, a downstream Woo order match is required: join `np_modal_checkout_interaction` (intent) with WooCommerce orders in the local DB on user + a time window (default 30 min proposed — see [open-questions.md](open-questions.md#4-paywall-completion-match-window)).

### 4. Prompt `action_type` is single-valued per prompt

The help doc lists `np_prompt_interaction.action_type` values as `donation`, `registration`, or `newsletters_subscription`. **Each prompt has exactly one `action_type`** — the intent the prompt is built for. This is in contrast to gates, which can offer multiple action paths (`signin` / `registration` / `checkout_button` / `donation`) within a single gate. Implication for queries: don't try to derive "what fraction of prompt impressions led to registration"; instead, scope the metric by prompt's own `action_type`.

---

## Global parameters

Automatically added to all Newspack custom events where applicable. Use these to segment any event by reader status or content context without extra configuration. Source: `class-googlesitekit.php`.

| Parameter | Values | What it tells you |
|---|---|---|
| `logged_in` | `yes` / `no` | Whether the reader was logged in when the event fired. |
| `is_reader` | `yes` / `no` | Logged into a reader account (vs. an admin/editor account). |
| `is_newsletter_subscriber` | `yes` / `no` | Subscribed to one or more newsletter lists. |
| `is_subscriber` | `yes` / `no` | Has an active paid subscription. |
| `is_donor` | `yes` / `no` | Has made a donation. |
| `post_id` | numeric | WordPress post ID on singular pages. **Set by Newspack**, not a GA4 standard. Available on all singular page views (`is_singular()`). |
| `categories` | comma-separated list | Categories on the post where the event fired. Singular pages only. |
| `author` | comma-separated list | Author names on the post where the event fired. Singular pages only. |

Standard GA4 globals (`ga_session_id`, `ga_session_number`, `session_engaged`, `engagement_time_msec`, `page_location`, `page_title`, `page_referrer`, `event_timestamp`, `user_pseudo_id`, `user_id`) are also present on every row by virtue of the GA4 export — they're not Newspack-added but they're available to queries.

**Important note for Tab 2 (Engagement) work:** `post_id` is set on `is_singular()` page views, which means it's a clean article-detection signal — `WHERE PARAM_INT('post_id') IS NOT NULL` reliably identifies singular content (posts, pages, custom post types). For specifically identifying *articles* vs other singular content, the doc has historically suggested URL pattern matching as the workaround; NPPD-1621 will add `post_type` as a custom dimension for the proper fix.

---

## Custom dimensions provisioned by Newspack

Newspack automatically provisions a standard set of GA4 custom dimensions on every publisher's GA4 property via `Newspack\GA4_Custom_Dimensions::provision()`. As of June 2026, the auto-provisioned list is:

| Parameter | Display name |
|---|---|
| `gate_post_id` | Gate Post ID |
| `is_reader` | Is Reader |
| `action_type` | Action Type |
| `action` | Action |
| `logged_in` | Logged In |
| `is_subscriber` | Is Subscriber |
| `is_donor` | Is Donor |
| `is_newsletter_subscriber` | Is Newsletter Subscriber |
| `newspack_popup_id` | Newspack Popup ID |
| `prompt_placement` | Prompt Placement |
| `prompt_frequency` | Prompt Frequency |
| `prompt_title` | Prompt Title |
| `gate_has_donation_block` | Gate Has Donation Block |
| `gate_has_registration_block` | Gate Has Registration Block |
| `gate_has_checkout_button` | Gate Has Checkout Button |
| `gate_has_registration_link` | Gate Has Registration Link |
| `gate_has_signin_link` | Gate Has Signin Link |
| `product_id` | Product ID |
| `product_type` | Product Type |
| `recurrence` | Recurrence |
| `price` | Price |
| `donation_frequency` | Donation Frequency |
| `donation_amount` | Donation Amount |
| `registration_method` | Registration Method |
| `lists` | Newsletter Lists |
| `categories` | Categories |
| `author` | Author |

27 dimensions provisioned out of GA4's 50-dimension limit. Provisioning is idempotent and re-runs monthly via Action Scheduler. Available room: 23 more dimensions before the GA4 cap.

**Known gaps for Insights:** `post_type` and `post_published_date` are not currently provisioned. NPPD-1621 tracks adding them. Until that ships:

- `post_type` workaround: combine `post_id IS NOT NULL` (singular page) with URL pattern matching for narrower filters
- `post_published_date` workaround: none in BQ — would require Tab 2 → wp_posts join via post_id. Article freshness metric deferred to v1.1.

Note: `post_id` is sent as an event parameter on every singular page view (per `class-googlesitekit.php`) but is NOT in the provisioned custom dimensions list. In BQ this is fine — it's accessible via `event_params` regardless. The distinction matters for GA4 UI reporting (where only registered dimensions are queryable) but not for direct BQ querying.

---

## Event catalog

### Reader identity events

#### `np_reader_registered`

Fires when a reader successfully creates a new account. **Only fires for Newspack registration methods** — not for admin-created accounts.

**Triggers:** Registration block, Newsletter Subscription Form block, "Create an account" modal.

| Parameter | What it tells you |
|---|---|
| `registration_method` | Which registration flow the reader used. |
| `newspack_popup_id` | ID of the Campaigns prompt that triggered this, if any. |
| `ab_test_id` | Test ID of the A/B test this prompt belongs to, if any. |
| `ab_variant` | Which variant the reader saw — `a` (control), `b`, `c`, etc. |
| `gate_post_id` | ID of the content gate that triggered this, if any. |
| `sso` | `true` if triggered by "Sign in with Google". |
| `referrer` | The page the reader came from. |

**Observed coverage** (BIGQUERY.md, 30-day sample, one publisher): `gate_post_id` ~90% — 10% gap, falls to session/user-scope attribution.

---

#### `np_reader_logged_in`

Fires when a reader successfully signs in using a Newspack login method.

**Triggers:** Sign-in modal (password or one-time code), magic sign-in link, "Sign in with Google".

| Parameter | What it tells you |
|---|---|
| `login_method` | How the reader signed in (password, OTP, magic link, SSO). |
| `newspack_popup_id` | ID of the prompt that triggered the login, if any. |
| `gate_post_id` | ID of the content gate that triggered the login, if any. |
| `sso` | `true` if triggered by "Sign in with Google". |

**Observed coverage** (BIGQUERY.md sample): `gate_post_id` ~74% — surprisingly high gate attribution on logins, since most logins come from gate signin forms.

---

#### `np_newsletter_subscribed`

Fires when a reader subscribes to one or more newsletter lists via a Newspack method.

**Triggers:** Registration block, Newsletter Subscription Form block, "Create an account" modal, My Account → Newsletters.

| Parameter | What it tells you |
|---|---|
| `newsletters_subscription_method` | Which signup flow the reader used. |
| `lists` | IDs of the lists the reader subscribed to. |
| `newspack_popup_id` | ID of the prompt that triggered the signup, if any. |
| `ab_test_id` | Test ID of the A/B test this prompt belongs to, if any. |
| `ab_variant` | Which variant the reader saw — `a` (control), `b`, `c`, etc. |
| `prompt_title` | Name of the prompt that triggered the signup, if any. |
| `gate_post_id` | ID of the content gate that triggered the signup, if any. |

**Notes:**

- `lists` is **mixed-type** in the BQ export — int for single-list signups, string (comma-separated) for multi-list. Always coalesce both fields. See [formulas/README.md](formulas/README.md).
- The help doc lists `gate_post_id` on this event, which is helpful — earlier analyst notes (BIGQUERY.md) said no `gate_post_id`. Help doc is authoritative.

---

### Prompt events

#### `np_prompt_interaction`

Fires when a reader interacts with a Newspack Campaigns prompt. This is your primary signal for prompt performance.

| Parameter | What it tells you |
|---|---|
| `newspack_popup_id` | Numeric ID of the prompt. Live BQ data stores this as `value.int_value`; the canonical queries cast it to STRING via `CAST(value.int_value AS STRING)` and fall back to `value.string_value` for safety. |
| `prompt_title` | Name of the prompt. |
| `prompt_placement` | Where the prompt appeared (inline, overlay, above-header, etc.). |
| `prompt_frequency` | Frequency setting on the prompt. |
| `action` | `loaded`, `seen`, `dismissed`, or `clicked`. |
| `action_type` | `donation`, `registration`, or `newsletters_subscription`. **Single-valued per prompt** — see update #4 above. |
| `ab_test_id` | Test ID of the A/B test this prompt belongs to, if any. |
| `ab_variant` | Which variant the reader saw — `a` (control), `b`, `c`, etc. |
| `prompt_has_donation` (a.k.a. `prompt_has_donation_block`) | Whether the prompt contains a Donate block. |
| `prompt_has_registration` (a.k.a. `prompt_has_registration_block`) | Whether the prompt contains a Registration block. |
| `prompt_has_newsletters_subscription` (a.k.a. `prompt_has_newsletters_subscription_block`) | Whether the prompt contains a Newsletter Subscription block. |

**Schema note (publisher drift):** live GA4 data on test publishers emits the no-`_block` form with `int_value = 1` (truthy). The Newspack help doc and earlier examples list the `_block` suffix with `string_value = 'yes'`. Both shapes may appear in the wild; the canonical BigQuery queries (see `docs/insights/formulas/tab-5-prompts.md`) defensively read both via `COALESCE(CAST(value.int_value AS STRING), value.string_value) IN ('1', 'yes')` so neither shape is silently dropped.

**Notes:**

- `action='clicked'` is captured rarely (BIGQUERY.md: ~2 clicks per 6,900 impressions in the sample). Treat click count as a floor for CTR analyses, not a real denominator.
- Prompts and Gates are **different surfaces** — don't mix without intent. Prompts surface via Campaigns; gates surface via the content-gate (paywall/regwall) flow.

---

### Content gate events

#### `np_gate_interaction`

Fires when a reader encounters a content gate. Captures what the gate offered and what the reader did.

| Parameter | What it tells you |
|---|---|
| `gate_post_id` | ID of the content gate. |
| `action` | `seen`, `dismissed`, or `form_submission`. |
| `action_type` | When `action` is `form_submission`: `registration`, `signin`, `donation`, or `checkout_button`. |
| `gate_has_donation_block` | Whether the gate contains a Donate block. |
| `gate_has_registration_block` | Whether the gate contains a Registration block. |
| `gate_has_checkout_button` | Whether the gate contains a Checkout Button block. |
| `gate_has_registration_link` | Whether the gate shows an inline link to open a registration modal. ~100% coverage. |
| `gate_has_signin_link` | Whether the gate shows an inline link to open a signin modal. ~100% coverage. |
| `donation_frequency` | When `action_type` is `donation`: `once`, `monthly`, or `annual`. |
| `donation_amount` | When `action_type` is `donation`: the amount submitted. |

**Additional parameters observed in production but not in the help doc** (provenance: BIGQUERY.md 30-day sample):

| Parameter | Coverage | Notes |
|---|---|---|
| `referrer` | 100% | Newspack-specific referrer, **distinct from** the standard `page_referrer`. |
| `product_id` | 0.3% | Only on `action_type='checkout_button'` rows. |
| `variation_ids` | 0.3% | Comma-separated. Only on `checkout_button` rows. |
| `product_type` | 0.3% | Only on `checkout_button` rows. Always `membership` in the sample. |
| `currency` | 0.3% | Only on `checkout_button` rows. |
| `is_variable` | 0.3% | Only on `checkout_button` rows. |

**Notes:**

- Inline conversion rate of a gate is measurable without any join — numerator is `action='form_submission'`, denominator is `action='seen'`, both scoped by `gate_post_id`.
- `checkout_button` action_type **does not mean checkout completed** — it means the user clicked the CTA and the modal opened. Follow into `np_modal_checkout_interaction` (+ Woo orders) for the rest of the flow.
- The `gate_has_*` flags describe the gate's configuration at render time. Useful for segmenting "gates with a donation block vs not."
- The `gate_has_*` flags appear on `np_gate_interaction` events only — they're not propagated to downstream completion events. For Influenced metrics that filter by gate configuration, scope on the upstream `np_gate_interaction(seen)` event.

---

### Revenue events

#### `np_modal_checkout_interaction`

Fires at each step of the modal checkout flow — the lightweight checkout that opens as an overlay rather than taking readers to a separate page.

| Parameter | What it tells you |
|---|---|
| `action` | `opened`, `loaded`, `dismissed`, `continue`, `back`, or `form_submission`. |
| `action_type` | `donation` or `checkout_button`. |
| `amount` | The transaction amount. |
| `currency` | Currency code. |
| `product_id` | WooCommerce product ID. |
| `product_type` | Type of product (subscription, donation, etc.). |
| `recurrence` | Billing frequency. |
| `newspack_popup_id` | ID of the prompt that triggered checkout, if any. |
| `ab_test_id` | Test ID of the A/B test this prompt belongs to, if any. |
| `ab_variant` | Which variant the reader saw — `a` (control), `b`, `c`, etc. |
| `gate_post_id` | ID of the content gate that triggered checkout, if any. |

**Additional parameters observed in production but not documented** (provenance: BIGQUERY.md 30-day sample):

| Parameter | Coverage | Notes |
|---|---|---|
| `variation_id` | 30% | |
| `variation_ids` | 46% | Comma-separated. |
| `price_summary` | 23% | Human-readable price string. |
| `referrer` | 76% | Newspack-specific referrer (distinct from `page_referrer`). Present on non-initial actions. |
| `opened_variations` (as `action` value) | — | Observed in the wild but not listed in the help doc's `action` enum. |

**Notes:**

- `amount` is **mixed-type** — int for whole-dollar amounts, double for sub-dollar. Always coalesce. See [formulas/README.md](formulas/README.md).
- `form_submission_success` is **deprecated** (see update #3 above). Use `gate_post_id` matching + Woo order join for paywall completion attribution.
- `gate_post_id` coverage on this event is ~42% per BIGQUERY.md sample — the biggest attribution gap in the schema. Param-tagging alone undercounts paywall conversions; session-scoped or user-scoped attribution is needed.

---

## Cross-event attribution rules

Every non-inline conversion needs an attribution rule. Pick based on the strength-of-signal vs coverage trade-off the metric can tolerate. Four rules, ordered strongest signal / fewest matches → loosest / most matches.

| Rule | What it matches | Strength | When to use |
|---|---|---|---|
| **A. Inline** | Conversion recorded on the gate event itself (`np_gate_interaction action='form_submission'`) | Strongest — user converted on the gate UI | Always start here for gate-conversion rate; numerator is just an event count |
| **B. Param-tagged (Direct)** | Completion event carries `gate_post_id` or `newspack_popup_id` | Strong — explicit link | Registration (~90% coverage), login (~74%); acceptable for checkout (~42%) |
| **C. Session-scoped** | Completion in the same `(user_pseudo_id, ga_session_id)` as a gate-seen | Suggestive — same visit | Fills the param-tagging gap for in-visit conversions |
| **D. User-scoped window (Influenced)** | Completion by the same `user_pseudo_id` within N days of a gate or prompt impression | Weakest — surface seen at all, within a window | Right model for paid checkout (multi-session deliberation), full reader-revenue funnel |

**Standard Influenced windows used across Insights:**

- **Free conversions (registration, newsletter signup):** 7 days
- **Paid conversions (subscription, donation):** 14 days

These are the windows applied throughout Tabs 4, 5, and 7 for the Influenced metrics, and in Tab 3 for the cross-cutting view.

**Per-conversion-type defaults:**

- **Registration, login** — A ∪ B for Direct; D (7d) for Influenced. High param-tagging coverage means session-scope is rarely needed for Direct.
- **Newsletter signup** — Help doc lists `gate_post_id` on `np_newsletter_subscribed`, so B is viable for Direct. D (7d) for Influenced. Fall back to C for the unattributed tail of Direct if needed.
- **Paid checkout** — B for Direct + Woo order match within a short window (~30 min, see [open-questions.md](open-questions.md#4-paywall-completion-match-window)); D (14d) for Influenced. Session-scoping alone loses too many legitimate checkouts.

---

## Data-quality caveats

Things that will silently skew a query if you don't plan for them. Provenance: BIGQUERY.md analyst observations + design experience.

- **Mixed-type params:** `amount` (int + double), `lists` (string + int). Always coalesce.
- **`ga_session_id` is not globally unique** — it's scoped to `user_pseudo_id`. Two different users' sessions can share an ID. Always join on `(user_pseudo_id, ga_session_id)`.
- **30-minute session cutoff** is hard-coded by GA4. Multi-session paid-checkout deliberation (see gate Monday, subscribe Thursday) is invisible to session-scoped joins. Use user-scoped attribution (Influenced) for paid.
- **`user_pseudo_id` is cookie-scoped** — cleared cookies, switched browsers, or incognito makes a reader look like a different user. Cross-device unification requires `user_id`, which only fires for authenticated users.
- **Intraday tables (`events_intraday_YYYYMMDD`)** are partial and revised continuously. Exclude today's date from long-window queries when stable counts matter.
- **Missing params aren't always a bug** — they may reflect the literal site state. `gate_post_id` missing on a conversion event often means the conversion happened on a follow-up page where the gate tag wasn't loaded. Check whether missing events are concentrated on specific pages before blaming the tag.
- **UTM params have low coverage** (~4%) — only present when the URL carried them. For reliable traffic attribution, prefer the top-level `collected_traffic_source.*` columns on the row (populated by GA4 based on referrer rules) or `session_traffic_source_last_click.*` for last-click attribution.
- **Coverage statistics in this doc are from one publisher's 30-day sample** (test property `customer-analytics-293121.analytics_400107557`). Real publisher data may show different patterns. Treat coverage numbers as order-of-magnitude indicators, not precise expectations.

---

## Cross-references

- Information architecture: [information-architecture.md](information-architecture.md)
- Architecture: [architecture.md](architecture.md)
- Open questions: [open-questions.md](open-questions.md)
- Schema reference (Woo subscriptions and donations): [formulas/subscription-donation-schema.md](formulas/subscription-donation-schema.md)
- Formula reference: [formulas/](formulas/) (one file per tab + schema reference)
- Custom dimensions implementation: `newspack-plugin includes/plugins/google-site-kit/class-ga4-custom-dimensions.php`
- Event param emission: `newspack-plugin includes/plugins/google-site-kit/class-googlesitekit.php`