# Newspack GA4 BigQuery — Analyst Foundation

Reference for analyzing Newspack-specific events in the GA4 BigQuery export.
Covers the 11 `np_*` event types, their params, attribution models, and
reusable query building blocks.

Two audiences:

- **Human analysts** writing ad-hoc SQL. Skim the TOC; jump to the event or
  pattern you need. Queries are copy-paste-able.
- **LLMs writing queries** on behalf of an analyst. Load the whole file; if
  tokens are tight, §2 is the compact summary.

Concrete counts come from one test property
(`customer-analytics-293121.analytics_400107557`) over a 30-day window
(`20260322`–`20260421`). They're illustrative of coverage and enum shapes, not
universal truths.

---

## 1. Contents

1. Contents
2. Quick reference (read-this-first summary)
3. Dataset access and query shape
4. Core concepts: gates, prompts, sessions, attribution
5. Universal event shape
6. Event catalog
   - 6.1 `np_gate_interaction`
   - 6.2 `np_prompt_interaction`
   - 6.3 `np_reader_registered`
   - 6.4 `np_reader_logged_in`
   - 6.5 `np_newsletter_subscribed`
   - 6.6 `np_modal_checkout_interaction`
   - 6.7 Subscription lifecycle events
7. Attribution models
8. Reusable CTEs (building blocks)
9. Recipe queries
10. Data-quality caveats

---

## 2. Quick reference

**Dataset shape.** Daily shards `events_YYYYMMDD`. Query the union:

```sql
FROM `<project>.<dataset>.events_*`
WHERE _TABLE_SUFFIX BETWEEN '20260322' AND '20260421'
```

**Param extraction.** `event_params` is a repeated struct. Subquery per key:

```sql
(SELECT value.int_value    FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS session_id,
(SELECT value.string_value FROM UNNEST(event_params) WHERE key = 'action')        AS action
```

**Every `np_*` event carries** (≥95% coverage unless noted):

- Session: `ga_session_id` (int), `ga_session_number` (int), `session_engaged`
  (string "1"/"0"), `engaged_session_event` (int), `engagement_time_msec` (int)
- Page: `page_location`, `page_title`, `page_referrer` (~85%), `post_id` (int),
  `author`, `categories`
- Reader state (string "yes"/"no"): `logged_in`, `is_reader`, `is_subscriber`,
  `is_donor`, `is_newsletter_subscriber`
- UTM (low coverage, only when URL carries them): `source`, `medium`,
  `campaign`, `term`, `content`
- Newspack plumbing: `batch_ordering_id`, `batch_page_id`, `transport_type`,
  `ignore_referrer`, `email_hash` (salted, not raw)

**Top-level columns that matter:** `event_name`, `event_timestamp` (µs since
epoch), `event_date`, `user_pseudo_id`, `user_id`,
`collected_traffic_source.{manual_source, manual_medium, manual_campaign_name}`,
`session_traffic_source_last_click.*`, `device.category`, `geo.country`.

**Event shortlist by use case:**

| I want to measure… | Event | Filter |
|---|---|---|
| Gate impressions | `np_gate_interaction` | `action='seen'` |
| Gate dismissals | `np_gate_interaction` | `action='dismissed'` |
| Gate inline conversions (user converted *on* the gate) | `np_gate_interaction` | `action='form_submission'` + `action_type` in (`signin`, `registration`, `checkout_button`) |
| Completed registrations | `np_reader_registered` | — |
| Completed logins | `np_reader_logged_in` | — |
| Completed newsletter signups | `np_newsletter_subscribed` | — |
| **Completed** paid checkouts | `np_modal_checkout_interaction` | `action='form_submission_success'` |
| Checkout *attempts* | `np_modal_checkout_interaction` | `action='form_submission'` |
| Prompt impressions | `np_prompt_interaction` | `action='seen'` |

**Gate-to-conversion links.** A gate's inline `form_submission` is *intent*;
the completion event is separate. Typical flows:

- Gate `form_submission action_type='registration'` → `np_reader_registered`
  (same session; `gate_post_id` set on 90% of registrations).
- Gate `form_submission action_type='signin'` → `np_reader_logged_in`
  (`gate_post_id` set on 74% of logins).
- Gate `form_submission action_type='checkout_button'` → opens modal →
  potentially `np_modal_checkout_interaction action='form_submission_success'`
  (`gate_post_id` set on 42% of modal checkouts — the biggest attribution gap).

**Pitfalls** (full list in §10):

- Use `form_submission_success`, not `form_submission`, for completed checkouts.
- `amount` on checkout is split across `value.int_value` and
  `value.double_value` — `COALESCE` both.
- `lists` on newsletter is split across string and int.
- `page_referrer` is null ~15% of the time.
- GA4 sessions cut off at 30 min idle. For paid-checkout deliberation that
  spans days, session-join under-attributes; prefer user-scoped windows.
- Gates (`np_gate_interaction`) and Prompts (`np_prompt_interaction`) are
  different surfaces — don't mix without intent.

---

## 3. Dataset access and query shape

### 3.1 Table layout

GA4 BigQuery export writes daily-sharded tables:

```
<project>.<dataset>.events_YYYYMMDD    -- finalized day
<project>.<dataset>.events_intraday_YYYYMMDD  -- current day, partial
```

The Newspack test property resolves to
`customer-analytics-293121.analytics_400107557.events_*`. Each property has
its own dataset.

Query the union with a wildcard suffix and scope by date:

```sql
FROM `<project>.<dataset>.events_*`
WHERE _TABLE_SUFFIX BETWEEN '20260322' AND '20260421'
```

`_TABLE_SUFFIX` is the substring after `events_`. `BETWEEN` over it uses BQ's
partition pruning — only the matching daily tables get scanned. Without this
filter, a query scans every day of history.

### 3.2 Nested row shape

Each row is one event. Useful top-level columns:

| Column | Type | Notes |
|---|---|---|
| `event_name` | string | e.g. `np_gate_interaction`, `page_view` |
| `event_timestamp` | int | microseconds since epoch; `TIMESTAMP_MICROS(event_timestamp)` to convert |
| `event_date` | string | `YYYYMMDD` |
| `user_pseudo_id` | string | anonymous cookie-scoped ID; stable per browser |
| `user_id` | string | populated only when the site called `gtag('set', {user_id: …})`; rare |
| `event_params` | array&lt;struct&gt; | repeated `key` + `value.{string,int,float,double}_value` |
| `user_properties` | array&lt;struct&gt; | same shape as event_params, rarely used |
| `collected_traffic_source.manual_source` | string | UTM source at event time |
| `collected_traffic_source.manual_medium` | string | UTM medium |
| `collected_traffic_source.manual_campaign_name` | string | UTM campaign |
| `session_traffic_source_last_click.*` | struct | last-click attribution at session level (channel-group fields) |
| `device.category` | string | `desktop` / `mobile` / `tablet` |
| `geo.country`, `geo.region`, `geo.city` | string | location |

### 3.3 Param extraction idiom

Every param lives in `event_params` as `(key, value_struct)`. Pull with a
scalar subquery against `UNNEST`:

```sql
(SELECT value.int_value    FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS session_id,
(SELECT value.string_value FROM UNNEST(event_params) WHERE key = 'action')        AS action
```

Pick the right value field. The coverage tables in §6 show which field each
param populates. For mixed-type params (rare) use `COALESCE`:

```sql
COALESCE(
  (SELECT value.int_value    FROM UNNEST(event_params) WHERE key = 'amount'),
  (SELECT CAST(value.double_value AS INT64) FROM UNNEST(event_params) WHERE key = 'amount')
) AS amount
```

### 3.4 Cost discipline

GA4 export volumes scale with traffic. Rules of thumb:

- Always narrow `_TABLE_SUFFIX`. Start with a 1–7 day window when exploring a
  new question; expand once the query is correct.
- Filter `event_name` early in the innermost CTE so BQ can prune.
- `SELECT event_params` and unnest in a CTE *once* — don't repeat
  `(SELECT … FROM UNNEST(event_params) …)` in an outer query when you can
  project columns once in a CTE and reuse them.
- Dry-run before running: the BQ console shows estimated bytes before
  execution.

---

## 4. Core concepts

### 4.1 Two publisher surfaces: Gates vs Prompts

Newspack has two separate content-interruption surfaces. They fire different
events; don't confuse them.

- **Gates** (`np_gate_interaction`) are the paywall-style overlays that
  interrupt gated content. Identified by `gate_post_id`. Tied to the Newspack
  Reader Activation / Memberships flow.
- **Prompts** (`np_prompt_interaction`) are the campaigns-style popups (inline
  boxes, overlays, scroll triggers). Identified by `newspack_popup_id` and the
  prompt taxonomy params (`prompt_placement`, `prompt_title`, `prompt_frequency`).
  Almost exclusively newsletter subscription (`action_type =
  'newsletters_subscription'` on 86% of rows in the test dataset).

A page can host both. Analyses should filter deliberately.

### 4.2 Reader state flags

Every `np_*` event carries the reader's state at event time:

| Flag | Meaning |
|---|---|
| `logged_in` | "yes"/"no" — WP user session active |
| `is_reader` | registered reader account exists |
| `is_subscriber` | has an active subscription |
| `is_donor` | has donated |
| `is_newsletter_subscriber` | subscribed to at least one newsletter |

Values are **strings** (`"yes"`/`"no"`), not booleans. Compare with
`= 'yes'`. Useful for segmentation ("gate performance for already-registered
readers who aren't subscribers" etc.).

### 4.3 Sessions

GA4 sessions are defined by `ga_session_id` (int) scoped to `user_pseudo_id`.
A session ends after 30 min of inactivity — not configurable in the export.

**Key implication:** `ga_session_id` is **not globally unique**. Always join
or group on `(user_pseudo_id, session_id)`.

`ga_session_number` counts sessions per pseudo-id (1 = first ever).
`session_engaged` ("1"/"0") reflects GA4's "engaged session" definition (10+
seconds, a conversion, or 2+ page views).

### 4.4 Attribution scope

Four ways to tie a conversion back to the thing that caused it, ordered from
strongest-signal/fewest-matches to loosest/most-matches. §7 has the full
comparison.

1. **Inline** — conversion is recorded on the gate event itself
   (`np_gate_interaction action='form_submission'`). No join needed.
2. **Param-tagged** — conversion event carries `gate_post_id`. Direct link
   but coverage is partial (90% reg, 74% login, 42% checkout).
3. **Session-scoped** — conversion happens in the same
   `(user_pseudo_id, session_id)` as a gate impression. Catches most
   within-visit conversions; breaks on multi-session deliberation.
4. **User-scoped window** — conversion happens within *N* days of a gate
   impression by the same `user_pseudo_id`. Right model for paid checkout.

### 4.5 What a "conversion" is

Newspack fires events at both *intent* and *completion* moments:

| Moment | Event |
|---|---|
| User clicks "Register" on a gate | `np_gate_interaction action='form_submission' action_type='registration'` (intent) |
| Registration actually created | `np_reader_registered` (completion) |
| User clicks "Subscribe" on a gate | `np_gate_interaction action='form_submission' action_type='checkout_button'` (intent) |
| User clicks "Sign in" on a gate + submits | `np_gate_interaction action='form_submission' action_type='signin'` (intent — the login may succeed or fail) |
| User logged in successfully | `np_reader_logged_in` (completion) |
| User submits checkout form | `np_modal_checkout_interaction action='form_submission'` (attempt — may fail validation) |
| Checkout fully completed | `np_modal_checkout_interaction action='form_submission_success'` (completion) |

The distinction matters. A gate's registration `form_submission` is *intent
to register* — the registration might fail (bad email, duplicate account).
The completion event is `np_reader_registered`. In-session these are usually
both present; when they diverge, the gate event is the UI signal and the
completion event is the outcome.

---

## 5. Universal event shape

Params present on nearly every `np_*` event (≥95% coverage). When writing
queries, assume these exist.

### 5.1 Session params

| Key | Value type | Notes |
|---|---|---|
| `ga_session_id` | int | event's session id |
| `ga_session_number` | int | 1-based session counter per `user_pseudo_id` |
| `session_engaged` | string | "1" or "0" |
| `engaged_session_event` | int | 1 if this event qualified the session as engaged |
| `engagement_time_msec` | int | time on page until event, in ms |

### 5.2 Page params

| Key | Value type | Notes |
|---|---|---|
| `page_location` | string | full URL of the page |
| `page_title` | string | `<title>` |
| `page_referrer` | string | `document.referrer` at page load; ~85% coverage |
| `post_id` | int | WP post ID of the page (if the URL resolves to a post) |
| `author` | string | post author slug |
| `categories` | string | comma-separated category slugs |

### 5.3 Reader-state params

All strings, values `"yes"` / `"no"`:

| Key | Coverage |
|---|---|
| `logged_in` | ~100% |
| `is_reader` | ~100% |
| `is_subscriber` | ~100% |
| `is_donor` | ~100% |
| `is_newsletter_subscriber` | ~100% |

### 5.4 UTM params (low coverage)

Only populated when the URL carries them:

| Key | Type | Coverage |
|---|---|---|
| `source` | string | 4% |
| `medium` | string | 4% |
| `campaign` | string | 4% |
| `term` | string | 1–2% |
| `content` | string | <1% |

For reliable traffic attribution prefer the top-level
`collected_traffic_source.*` columns (populated by GA4 based on referrer
rules, not just URL params) or
`session_traffic_source_last_click.*`.

### 5.5 Plumbing (usually ignore)

`batch_ordering_id`, `batch_page_id`, `transport_type`, `ignore_referrer`,
`email_hash` (salted identifier, not useful for joins). Document-existence
matters only for schema completeness; analysts rarely query these.

---

## 6. Event catalog

Coverage percentages are row-level over the 30-day sample. Treat as "which
params to rely on" signal, not exact quotas.

### 6.1 `np_gate_interaction`

Content-gate interactions. The workhorse event.

**Key params:**

| Key | Type | Coverage | Notes |
|---|---|---|---|
| `action` | string | 100% | `seen` / `form_submission` / `dismissed` (see below) |
| `action_type` | string | 7% | only on `form_submission` rows; values `signin` / `registration` / `checkout_button` |
| `gate_post_id` | int | 100% | the gate's WP post ID — always present |
| `referrer` | string | 100% | Newspack-specific referrer (distinct from `page_referrer`) |
| `gate_has_donation_block` | string | ~100% | "yes"/"no" — feature presence on the gate |
| `gate_has_registration_block` | string | ~100% | |
| `gate_has_registration_link` | string | ~100% | |
| `gate_has_signin_link` | string | ~100% | |
| `gate_has_checkout_button` | string | ~100% | |
| `product_id` | int | 0.3% | only on `action_type='checkout_button'` rows |
| `variation_ids` | string | 0.3% | comma-separated |
| `product_type` | string | 0.3% | always `membership` in the sample |
| `currency`, `is_variable` | string | 0.3% | |

**`action` enum (sample counts):**

| `action` | n | Meaning |
|---|---|---|
| `seen` | 70,429 | gate rendered / visible |
| `form_submission` | 5,590 | user submitted a form on the gate |
| `dismissed` | 3,298 | user closed the gate |

**`action_type` enum (only when `action='form_submission'`):**

| `action_type` | n | Meaning |
|---|---|---|
| `signin` | 4,208 | submitted the inline signin form |
| `registration` | 1,107 | submitted the inline registration form |
| `checkout_button` | 275 | clicked the checkout CTA (has `product_id`/`variation_ids`/`currency`) |

**Notes:**

- The inline conversion rate of a gate is measurable without any join —
  numerator is `action='form_submission'`, denominator is `action='seen'`,
  both scoped by `gate_post_id`.
- `checkout_button` does not mean checkout completed; it means the user
  clicked the button and the modal opened. Follow into
  `np_modal_checkout_interaction` for completion.
- `gate_has_*` flags describe the gate's configuration at render time; good
  for segmenting "gates that have a donation block vs not".

### 6.2 `np_prompt_interaction`

Newspack Campaigns prompts (popups, inline boxes, overlays). Distinct surface
from gates.

**Key params:**

| Key | Type | Coverage | Notes |
|---|---|---|---|
| `action` | string | 100% | `seen` / `dismissed` / `clicked` |
| `action_type` | string | 100% | `newsletters_subscription` (86%) or `undefined` (14%) |
| `newspack_popup_id` | int | 97% | WP post ID of the prompt |
| `prompt_title` | string | 100% | |
| `prompt_placement` | string | 100% | inline / overlay / etc. |
| `prompt_frequency` | string | 100% | show-rule |
| `prompt_has_newsletters_subscription` | int | 86% | flag 0/1 |
| `prompt_id` | int | 2.7% | rare; when present, distinct from `newspack_popup_id` |

**`action` enum:** `seen` (6,896), `dismissed` (55), `clicked` (2). Clicks are
very rarely captured — treat the click count as a floor, not a real CTR
denominator.

**Notes:**

- Prompts do not carry `gate_post_id`. A conversion triggered by a prompt
  would usually show up as `np_newsletter_subscribed` with
  `newspack_popup_id` set (see §6.5).

### 6.3 `np_reader_registered`

Completed registrations.

**Key params:**

| Key | Type | Coverage | Notes |
|---|---|---|---|
| `registration_method` | string | 100% | see enum below |
| `sso` | string | 34% | `"yes"` when via Google SSO; absent otherwise |
| `gate_post_id` | int | 90% | 10% gap — session-scope or user-scope attribution fills this |
| `newspack_popup_id` | int | 0.5% | when registration came via a prompt |

**`registration_method` enum:**

| Value | n |
|---|---|
| `auth-form` | 371 |
| `google` | 194 |
| `newsletters-subscription` | 7 |
| `newsletters-subscription-popup` | 3 |

`sso='yes'` is equivalent to `registration_method='google'`.

### 6.4 `np_reader_logged_in`

Completed logins. Surprisingly high gate attribution — 74% of logins come
from a gate signin form.

**Key params:**

| Key | Type | Coverage | Notes |
|---|---|---|---|
| `login_method` | string | 100% | login surface used |
| `sso` | string | 15% | `"yes"` on SSO logins |
| `gate_post_id` | int | 74% | |

### 6.5 `np_newsletter_subscribed`

Completed newsletter signups.

**Key params:**

| Key | Type | Coverage | Notes |
|---|---|---|---|
| `newsletters_subscription_method` | string | 100% | signup surface |
| `lists` | string OR int | 100% | **mixed type** — see §10 |
| `newspack_popup_id` | int | 4% | when signup came via a prompt |

**Notes:**

- No `gate_post_id` — newsletter signups aren't directly gate-attributed in
  the event params. Use session-scope for attribution.
- `lists` encodes the subscribed list IDs. In the sample, 218 rows have
  int-typed lists (single-list signups) and 40 have string-typed lists
  (multi-list, comma-separated). Coalesce both value fields and cast.

### 6.6 `np_modal_checkout_interaction`

Modal-checkout surface: every interaction with the checkout modal fires one
of these, with `action` identifying the step.

**Key params:**

| Key | Type | Coverage | Notes |
|---|---|---|---|
| `action` | string | 100% | 8 values — see enum below |
| `action_type` | string | 76% | flow type; see enum below |
| `product_type` | string | 76% | `membership` (96%) or `product` (4%) |
| `product_id` | int | 94% | |
| `variation_id` | int | 30% | |
| `variation_ids` | string | 46% | comma-separated |
| `recurrence` | string | 30% | `year` (257) / `month` (80) |
| `amount` | int OR double | 30% | **mixed type** — see §10 |
| `price_summary` | string | 23% | human-readable price string |
| `currency` | string | 76% | |
| `gate_post_id` | int | 42% | **big attribution gap** |
| `referrer` | string | 76% | Newspack referrer — only present on non-initial actions |

**`action` enum (sample counts over 1,125 rows):**

| `action` | n | Meaning |
|---|---|---|
| `opened_variations` | 410 | user opened product-variation picker |
| `dismissed` | 213 | user closed the modal |
| `loaded` | 163 | modal finished loading |
| `opened` | 143 | modal opened |
| `continue` | 68 | user clicked continue on a multi-step form |
| `form_submission` | 60 | user submitted the checkout form (*attempt*) |
| `form_submission_success` | 56 | checkout **completed** |
| `back` | 12 | user went back |

**`action_type` enum (flow type, 76% coverage):**

| `action_type` | n |
|---|---|
| `checkout_button` | 827 |
| `subscription_switch` | 8 |
| `subscription_renewal` | 7 |
| `renewal_early` | 5 |
| `resubscribe` | 3 |
| `pay_order` | 3 |
| `change_payment_method` | 3 |

**Notes:**

- **`form_submission` ≠ `form_submission_success`**. 60 attempts, 56 successes
  in the sample — ~7% fail at the payment step. Use
  `form_submission_success` for completed-conversion analyses, and use the
  pair for "checkout attempt success rate".
- The 42% `gate_post_id` coverage on checkout is the primary motivator for
  session / user-scoped attribution.

### 6.7 Subscription lifecycle events (low-volume)

These fire on subscription state changes. Low volume in most datasets —
document for completeness, but expect handful-of-rows counts.

| Event | Distinct params beyond universal |
|---|---|
| `np_payment_method_added` | `payment_method` (string) |
| `np_payment_method_changed` | `subscription_id` (int), `update_all_subscriptions` (string "yes"/"no") |
| `np_subscription_switched` | `subscription_id` (int), `upgraded_or_downgraded` (string) |
| `np_subscription_renewal_early` | `subscription_id` (int) |
| `np_product_reordered` | `order_id` (int), `product_id` (int) |

None of these carry `gate_post_id`. They're post-conversion lifecycle
signals, relevant for retention / LTV analyses rather than acquisition
attribution.

---

## 7. Attribution models

Every non-inline conversion needs an attribution rule. Pick based on the
strength-of-signal vs coverage trade-off your analysis can tolerate.

### 7.1 The four buckets

| Rule | What it matches | Strength | When to use |
|---|---|---|---|
| **A. Inline** | Conversion recorded on the gate event itself (`np_gate_interaction action='form_submission'`) | Strongest — user converted on the gate UI | Always start here for gate-conversion rate; the numerator is just an event count |
| **B. Param-tagged** | Completion event carries `gate_post_id` | Strong — explicit link | Registration (90%), login (74%); acceptable for checkout (42%) |
| **C. Session-scoped** | Completion in the same `(user_pseudo_id, ga_session_id)` as a gate-seen | Suggestive — same visit | Fills the param-tagging gap for in-visit conversions |
| **D. User-scoped window** | Completion by the same `user_pseudo_id` within *N* days of a gate-seen | Weakest — gate seen at all, within a window | Right model for paid checkout (multi-session deliberation) |

### 7.2 Tightening within "session-scoped"

Within C, three sub-rules from weakest to strongest:

- **C0. Any order** — session contains both a gate-seen and a conversion.
  Includes sessions where the user converted *first* and saw the gate later —
  not causal. Use only for sanity-checking against other methods.
- **C1. Gate before conversion** — first gate-seen timestamp precedes first
  conversion timestamp.
- **C2. Tight coupling** — conversion event's immediate predecessor in the
  session (filtering to page_view + gate + conversion events) is a gate-seen.
  No `page_view` between gate and conversion.
- **C3. Link-through** — gate seen on page A; page_view on page B with
  `page_referrer=A`; conversion on B. The user clicked a link on the gate
  page and converted on the landed page. Captures link-style gate CTAs that
  C2 misses.

Queries for C1, C2, C3 are in §9. They're complementary: C2 catches inline /
same-page flows, C3 catches click-through flows, and C1 is the union-ish
superset.

### 7.3 Picking a rule

Per conversion type, defaults that usually work:

- **Registration, login** — A ∪ B. High param-tagging coverage means you
  rarely need C.
- **Newsletter signup** — C1 (or param-join via `newspack_popup_id` for
  prompt-driven). No `gate_post_id` on the completion event.
- **Checkout** — B ∪ D (user-scoped 30-day window). Session-scoping alone
  loses too many legitimate checkouts. D will over-attribute on high-traffic
  users; consider "first gate seen in window" or "last gate before checkout"
  when aggregating per gate.

### 7.4 Per-gate attribution rule

When a user sees multiple gates in the attribution window and then converts,
which gate gets credit?

| Rule | When |
|---|---|
| **All gates in window** | Inflates counts. Useful for "did any version of this gate ever precede a conversion" but not for per-gate rates. |
| **Last gate before conversion** | **Recommended default.** Causally honest; per-gate numbers sum cleanly across gates. |
| **First gate in window** | Rewards top-of-funnel gates. |

---

## 8. Reusable CTEs

Copy-paste building blocks. Compose them in a single `WITH` chain. All assume
`_TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'` and `<project>.<dataset>`
are filled in.

### 8.1 `base_events` — useful top-level columns

```sql
WITH base_events AS (
  SELECT
    event_timestamp,
    event_date,
    event_name,
    user_pseudo_id,
    user_id,
    collected_traffic_source.manual_source        AS traffic_source,
    collected_traffic_source.manual_medium        AS traffic_medium,
    collected_traffic_source.manual_campaign_name AS traffic_campaign,
    device.category                               AS device_category,
    geo.country                                   AS country,
    event_params
  FROM `<project>.<dataset>.events_*`
  WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
)
```

### 8.2 `np_events` — Newspack events with common params pre-extracted

```sql
, np_events AS (
  SELECT
    b.event_timestamp,
    b.event_date,
    b.event_name,
    b.user_pseudo_id,
    b.traffic_source, b.traffic_medium, b.traffic_campaign,
    b.device_category, b.country,
    (SELECT value.int_value    FROM UNNEST(b.event_params) WHERE key = 'ga_session_id')  AS session_id,
    (SELECT value.string_value FROM UNNEST(b.event_params) WHERE key = 'action')         AS action,
    (SELECT value.string_value FROM UNNEST(b.event_params) WHERE key = 'action_type')    AS action_type,
    (SELECT value.int_value    FROM UNNEST(b.event_params) WHERE key = 'gate_post_id')   AS gate_post_id,
    (SELECT value.int_value    FROM UNNEST(b.event_params) WHERE key = 'post_id')        AS post_id,
    (SELECT value.string_value FROM UNNEST(b.event_params) WHERE key = 'page_location')  AS page_location,
    (SELECT value.string_value FROM UNNEST(b.event_params) WHERE key = 'page_referrer')  AS page_referrer,
    (SELECT value.string_value FROM UNNEST(b.event_params) WHERE key = 'logged_in')      AS logged_in,
    (SELECT value.string_value FROM UNNEST(b.event_params) WHERE key = 'is_subscriber')  AS is_subscriber,
    b.event_params  -- keep for event-specific fields
  FROM base_events b
  WHERE STARTS_WITH(b.event_name, 'np_')
)
```

### 8.3 Per-surface building blocks

```sql
-- Gate impressions
, gate_impressions AS (
  SELECT user_pseudo_id, session_id, event_timestamp, gate_post_id, page_location
  FROM np_events
  WHERE event_name = 'np_gate_interaction' AND action = 'seen'
)

-- Gate inline conversions (intent captured on the gate itself)
, gate_inline_conversions AS (
  SELECT
    user_pseudo_id, session_id, event_timestamp, gate_post_id,
    action_type,  -- 'signin' | 'registration' | 'checkout_button'
    page_location
  FROM np_events
  WHERE event_name = 'np_gate_interaction' AND action = 'form_submission'
)

-- Sessions with at least one gate impression
, gated_sessions AS (
  SELECT DISTINCT user_pseudo_id, session_id
  FROM gate_impressions
)

-- Prompt impressions
, prompt_impressions AS (
  SELECT
    user_pseudo_id, session_id, event_timestamp,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key = 'newspack_popup_id') AS popup_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key = 'prompt_title')      AS prompt_title,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key = 'prompt_placement')  AS prompt_placement
  FROM np_events
  WHERE event_name = 'np_prompt_interaction' AND action = 'seen'
)
```

### 8.4 Completion events

```sql
-- Registrations
, registrations AS (
  SELECT
    user_pseudo_id, session_id, event_timestamp, gate_post_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='registration_method') AS method,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='sso')                 AS sso
  FROM np_events
  WHERE event_name = 'np_reader_registered'
)

-- Logins
, logins AS (
  SELECT
    user_pseudo_id, session_id, event_timestamp, gate_post_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='login_method') AS method,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='sso')          AS sso
  FROM np_events
  WHERE event_name = 'np_reader_logged_in'
)

-- Newsletter signups
, newsletter_signups AS (
  SELECT
    user_pseudo_id, session_id, event_timestamp,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='newsletters_subscription_method') AS method,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='newspack_popup_id')               AS popup_id
  FROM np_events
  WHERE event_name = 'np_newsletter_subscribed'
)

-- Checkout completions (NOT attempts)
, checkout_completions AS (
  SELECT
    user_pseudo_id, session_id, event_timestamp, gate_post_id,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='product_id')   AS product_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='product_type') AS product_type,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='recurrence')   AS recurrence,
    COALESCE(
      (SELECT value.int_value                    FROM UNNEST(event_params) WHERE key='amount'),
      (SELECT CAST(value.double_value AS INT64)  FROM UNNEST(event_params) WHERE key='amount')
    ) AS amount
  FROM np_events
  WHERE event_name = 'np_modal_checkout_interaction' AND action = 'form_submission_success'
)

-- Checkout attempts (for attempt→success funnel analysis)
, checkout_attempts AS (
  SELECT user_pseudo_id, session_id, event_timestamp, gate_post_id
  FROM np_events
  WHERE event_name = 'np_modal_checkout_interaction' AND action = 'form_submission'
)
```

### 8.5 Session rollup (for ordering-aware attribution)

```sql
, session_rollup AS (
  SELECT
    user_pseudo_id,
    session_id,
    MIN(IF(event_name='np_gate_interaction'           AND action='seen',                    event_timestamp, NULL)) AS first_gate_ts,
    MIN(IF(event_name='np_reader_registered',                                                 event_timestamp, NULL)) AS first_reg_ts,
    MIN(IF(event_name='np_reader_logged_in',                                                  event_timestamp, NULL)) AS first_login_ts,
    MIN(IF(event_name='np_newsletter_subscribed',                                             event_timestamp, NULL)) AS first_newsletter_ts,
    MIN(IF(event_name='np_modal_checkout_interaction' AND action='form_submission_success', event_timestamp, NULL)) AS first_checkout_ts
  FROM np_events
  GROUP BY user_pseudo_id, session_id
)
```

---

## 9. Recipe queries

Named end-to-end analyses. Each is a complete, runnable query. Replace
`<project>.<dataset>` and the date window.

### 9.1 Per-gate inline conversion funnel

No joins required — uses only `np_gate_interaction`. Good sanity baseline.

```sql
SELECT
  (SELECT value.int_value FROM UNNEST(event_params) WHERE key='gate_post_id') AS gate_post_id,
  COUNTIF(action = 'seen')                                                    AS impressions,
  COUNTIF(action = 'dismissed')                                               AS dismissals,
  COUNTIF(action = 'form_submission' AND
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action_type') = 'registration') AS inline_registrations,
  COUNTIF(action = 'form_submission' AND
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action_type') = 'signin')        AS inline_signins,
  COUNTIF(action = 'form_submission' AND
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action_type') = 'checkout_button') AS checkout_clicks
FROM (
  SELECT event_params,
         (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action') AS action
  FROM `<project>.<dataset>.events_*`
  WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
    AND event_name = 'np_gate_interaction'
)
GROUP BY gate_post_id
ORDER BY impressions DESC;
```

### 9.2 Cross-model attribution summary

Reproduces the headline counts across rules A/B/C1/C2/D (§7). Useful for
picking an attribution model — see the attrition at each tightening step.

```sql
WITH base_events AS (
  SELECT * FROM `<project>.<dataset>.events_*`
  WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
),
np_events AS (
  SELECT
    event_timestamp, event_name, user_pseudo_id,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='ga_session_id') AS session_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')        AS action,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action_type')   AS action_type,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='gate_post_id')  AS gate_post_id
  FROM base_events
  WHERE STARTS_WITH(event_name, 'np_')
),
session_rollup AS (
  SELECT
    user_pseudo_id, session_id,
    MIN(IF(event_name='np_gate_interaction'           AND action='seen',                    event_timestamp, NULL)) AS first_gate_ts,
    MIN(IF(event_name='np_reader_registered',                                               event_timestamp, NULL)) AS first_reg_ts,
    MIN(IF(event_name='np_modal_checkout_interaction' AND action='form_submission_success', event_timestamp, NULL)) AS first_checkout_ts
  FROM np_events
  GROUP BY user_pseudo_id, session_id
)
SELECT
  -- Total gated sessions (denominator)
  COUNTIF(first_gate_ts IS NOT NULL)                                                              AS gated_sessions,

  -- Registration
  COUNTIF(first_reg_ts IS NOT NULL)                                                               AS all_regs,
  (SELECT COUNT(*) FROM np_events
    WHERE event_name='np_gate_interaction' AND action='form_submission'
      AND action_type='registration')                                                             AS gate_inline_regs,              -- A
  (SELECT COUNT(*) FROM np_events WHERE event_name='np_reader_registered' AND gate_post_id IS NOT NULL) AS reg_with_gate_post_id,  -- B
  COUNTIF(first_gate_ts IS NOT NULL AND first_reg_ts IS NOT NULL)                                 AS reg_session_any_order,        -- C0
  COUNTIF(first_gate_ts IS NOT NULL AND first_reg_ts IS NOT NULL AND first_gate_ts < first_reg_ts) AS reg_session_gate_first,      -- C1

  -- Checkout
  COUNTIF(first_checkout_ts IS NOT NULL)                                                          AS all_checkouts,
  (SELECT COUNT(*) FROM np_events
    WHERE event_name='np_modal_checkout_interaction' AND action='form_submission_success'
      AND gate_post_id IS NOT NULL)                                                               AS checkout_with_gate_post_id,   -- B
  COUNTIF(first_gate_ts IS NOT NULL AND first_checkout_ts IS NOT NULL)                            AS checkout_session_any_order,   -- C0
  COUNTIF(first_gate_ts IS NOT NULL AND first_checkout_ts IS NOT NULL AND first_gate_ts < first_checkout_ts) AS checkout_session_gate_first  -- C1
FROM session_rollup;
```

### 9.3 Tight coupling — conversion's immediate predecessor is a gate-seen

Rule C2 (§7.2). Strongest in-session signal: no `page_view` between the gate
and the conversion.

```sql
WITH base AS (
  SELECT
    event_timestamp, user_pseudo_id,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='ga_session_id') AS session_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')        AS action,
    event_name
  FROM `<project>.<dataset>.events_*`
  WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
),
tagged AS (
  SELECT event_timestamp, user_pseudo_id, session_id,
    CASE
      WHEN event_name = 'page_view'                                                       THEN 'page_view'
      WHEN event_name = 'np_gate_interaction'           AND action = 'seen'               THEN 'gate_seen'
      WHEN event_name = 'np_modal_checkout_interaction' AND action = 'form_submission_success' THEN 'checkout'
      WHEN event_name = 'np_reader_registered'                                            THEN 'registration'
      WHEN event_name = 'np_reader_logged_in'                                             THEN 'login'
      WHEN event_name = 'np_newsletter_subscribed'                                        THEN 'newsletter'
    END AS kind
  FROM base
  WHERE event_name IN (
    'page_view','np_gate_interaction','np_modal_checkout_interaction',
    'np_reader_registered','np_reader_logged_in','np_newsletter_subscribed'
  )
),
with_prev AS (
  SELECT *,
    LAG(kind) OVER (
      PARTITION BY user_pseudo_id, session_id
      ORDER BY event_timestamp,
        CASE kind WHEN 'page_view' THEN 1 WHEN 'gate_seen' THEN 2 ELSE 3 END
    ) AS prev_kind
  FROM tagged
  WHERE kind IS NOT NULL
)
SELECT
  kind AS conversion_type,
  COUNT(*)                          AS total,
  COUNTIF(prev_kind = 'gate_seen')  AS prev_was_gate_seen,
  COUNTIF(prev_kind = 'page_view')  AS prev_was_page_view,
  COUNTIF(prev_kind IS NULL)        AS no_prev_in_session
FROM with_prev
WHERE kind IN ('checkout','registration','login','newsletter')
GROUP BY kind
ORDER BY kind;
```

The `ORDER BY` tiebreaker ensures that when a `gate_seen` and a conversion
share the same microsecond, the gate sorts first so the conversion's
`prev_kind` sees it.

### 9.4 Link-through — gate on A, conversion on B with `page_referrer=A`

Rule C3 (§7.2). Captures the "click the CTA, land on a new page, convert
there" flow that C2 misses.

```sql
WITH base AS (
  SELECT
    event_timestamp, user_pseudo_id,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='ga_session_id') AS session_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')        AS action,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='page_location') AS page_location,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='page_referrer') AS page_referrer,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='gate_post_id')  AS gate_post_id,
    event_name
  FROM `<project>.<dataset>.events_*`
  WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
),
gate_views AS (
  SELECT user_pseudo_id, session_id, event_timestamp AS gate_ts, page_location AS gate_page, gate_post_id
  FROM base WHERE event_name = 'np_gate_interaction' AND action = 'seen'
),
page_views AS (
  SELECT user_pseudo_id, session_id, event_timestamp AS pv_ts, page_location AS pv_location, page_referrer AS pv_referrer
  FROM base WHERE event_name = 'page_view'
),
conversions AS (
  SELECT user_pseudo_id, session_id, event_timestamp AS conv_ts, page_location AS conv_page,
    CASE
      WHEN event_name = 'np_modal_checkout_interaction' THEN 'checkout'
      WHEN event_name = 'np_reader_registered'          THEN 'registration'
      WHEN event_name = 'np_reader_logged_in'           THEN 'login'
      WHEN event_name = 'np_newsletter_subscribed'      THEN 'newsletter'
    END AS conversion_type
  FROM base
  WHERE (event_name = 'np_modal_checkout_interaction' AND action = 'form_submission_success')
     OR  event_name IN ('np_reader_registered','np_reader_logged_in','np_newsletter_subscribed')
)
SELECT
  c.conversion_type,
  COUNT(DISTINCT CONCAT(c.user_pseudo_id, CAST(c.session_id AS STRING), CAST(c.conv_ts AS STRING))) AS link_through_conversions,
  COUNT(DISTINCT g.gate_post_id) AS distinct_gates_implicated
FROM conversions c
JOIN page_views pv
  ON  pv.user_pseudo_id = c.user_pseudo_id
  AND pv.session_id     = c.session_id
  AND pv.pv_ts          < c.conv_ts
  AND pv.pv_location    = c.conv_page
JOIN gate_views g
  ON  g.user_pseudo_id  = c.user_pseudo_id
  AND g.session_id      = c.session_id
  AND g.gate_ts         < pv.pv_ts
  AND pv.pv_referrer    = g.gate_page
GROUP BY c.conversion_type
ORDER BY c.conversion_type;
```

### 9.5 User-scoped checkout attribution (rule D)

30-day window — catches multi-session paid-checkout deliberation that
session-scoping loses. `event_timestamp` is µs-since-epoch (int), so the
window comparison is straight integer arithmetic.

```sql
DECLARE window_usec INT64 DEFAULT 30 * 24 * 60 * 60 * 1000000;  -- 30 days in µs

WITH base AS (
  SELECT event_timestamp, event_name, user_pseudo_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')       AS action,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='gate_post_id') AS gate_post_id
  FROM `<project>.<dataset>.events_*`
  WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
),
gate_seens AS (
  SELECT user_pseudo_id, event_timestamp AS gate_ts, gate_post_id
  FROM base WHERE event_name = 'np_gate_interaction' AND action = 'seen'
),
checkouts AS (
  SELECT user_pseudo_id, event_timestamp AS checkout_ts
  FROM base WHERE event_name = 'np_modal_checkout_interaction' AND action = 'form_submission_success'
),
matched AS (
  SELECT
    c.user_pseudo_id,
    c.checkout_ts,
    -- last gate before the checkout, within the window
    ARRAY_AGG(g.gate_post_id ORDER BY g.gate_ts DESC LIMIT 1)[OFFSET(0)] AS last_gate_post_id,
    COUNT(g.gate_ts) > 0 AS any_gate_in_window
  FROM checkouts c
  LEFT JOIN gate_seens g
    ON  g.user_pseudo_id = c.user_pseudo_id
    AND g.gate_ts        < c.checkout_ts
    AND g.gate_ts        >= c.checkout_ts - window_usec
  GROUP BY c.user_pseudo_id, c.checkout_ts
)
SELECT
  last_gate_post_id,
  COUNTIF(any_gate_in_window)            AS attributed_checkouts,
  COUNTIF(NOT any_gate_in_window)        AS unattributed_checkouts,
  COUNT(*)                               AS total_checkouts
FROM matched
GROUP BY last_gate_post_id
ORDER BY attributed_checkouts DESC;
```

To widen or narrow the window, change `window_usec`. To use "first gate in
window" instead of last, flip the `ORDER BY` inside `ARRAY_AGG` to `ASC`.

### 9.6 Traffic source × gated-session breakdown

Where are gate-converting users actually coming from?

```sql
WITH base AS (
  SELECT
    collected_traffic_source.manual_source        AS traffic_source,
    collected_traffic_source.manual_medium        AS traffic_medium,
    event_name,
    user_pseudo_id,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='ga_session_id') AS session_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')        AS action
  FROM `<project>.<dataset>.events_*`
  WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
    AND event_name IN ('np_gate_interaction','np_reader_registered','np_modal_checkout_interaction')
),
gated AS (
  SELECT DISTINCT user_pseudo_id, session_id, traffic_source, traffic_medium
  FROM base WHERE event_name='np_gate_interaction' AND action='seen'
),
converted AS (
  SELECT user_pseudo_id, session_id,
         event_name = 'np_reader_registered' AS did_register,
         event_name = 'np_modal_checkout_interaction' AND action='form_submission_success' AS did_checkout
  FROM base
  WHERE  event_name = 'np_reader_registered'
      OR (event_name = 'np_modal_checkout_interaction' AND action = 'form_submission_success')
)
SELECT
  g.traffic_source,
  g.traffic_medium,
  COUNT(DISTINCT CONCAT(g.user_pseudo_id, CAST(g.session_id AS STRING))) AS gated_sessions,
  COUNTIF(c.did_register)                                                AS regs_in_session,
  COUNTIF(c.did_checkout)                                                AS checkouts_in_session
FROM gated g
LEFT JOIN converted c USING (user_pseudo_id, session_id)
GROUP BY traffic_source, traffic_medium
ORDER BY gated_sessions DESC
LIMIT 50;
```

### 9.7 Prompt performance by placement

```sql
SELECT
  (SELECT value.string_value FROM UNNEST(event_params) WHERE key='prompt_placement') AS placement,
  (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action_type')      AS action_type,
  COUNTIF((SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')='seen')      AS impressions,
  COUNTIF((SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')='dismissed') AS dismissals,
  COUNTIF((SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')='clicked')   AS clicks
FROM `<project>.<dataset>.events_*`
WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
  AND event_name = 'np_prompt_interaction'
GROUP BY placement, action_type
ORDER BY impressions DESC;
```

### 9.8 Checkout attempt → success rate

Uses the `form_submission` / `form_submission_success` pair to measure
checkout friction.

```sql
WITH ck AS (
  SELECT
    user_pseudo_id,
    (SELECT value.int_value    FROM UNNEST(event_params) WHERE key='ga_session_id') AS session_id,
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key='action')        AS action
  FROM `<project>.<dataset>.events_*`
  WHERE _TABLE_SUFFIX BETWEEN '{{start}}' AND '{{end}}'
    AND event_name = 'np_modal_checkout_interaction'
)
SELECT
  COUNTIF(action = 'form_submission')         AS attempts,
  COUNTIF(action = 'form_submission_success') AS successes,
  SAFE_DIVIDE(COUNTIF(action='form_submission_success'), COUNTIF(action='form_submission')) AS success_rate
FROM ck;
```

---

## 10. Data-quality caveats

Things that will silently skew a query if you don't plan for them.

### 10.1 Mixed-type params

Some params are stored in multiple `value.*` fields across rows. Always
coalesce.

| Event | Param | Fields | Fix |
|---|---|---|---|
| `np_modal_checkout_interaction` | `amount` | int + double | `COALESCE(int_value, CAST(double_value AS INT64))` |
| `np_newsletter_subscribed` | `lists` | string + int | coalesce + cast to string |

### 10.2 Coverage gaps

Use §6 coverage figures to judge reliability:

- `gate_post_id` on checkout: 42% — the big gap. Session / user scope it.
- `page_referrer`: ~85% on gates, lower elsewhere. Nulls mostly come from
  Referrer-Policy headers and privacy browsers.
- UTM params (`source`/`medium`/`campaign`): 4% coverage. Prefer
  `collected_traffic_source.*` top-level columns.
- `product_id`/`variation_ids`/`currency` on gate: 0.3% — only present on
  `action_type='checkout_button'` rows, which is the intended subset.

### 10.3 Sessions are not globally unique

`ga_session_id` is an int scoped to `user_pseudo_id`. Two different users'
sessions can share an ID. Always join on `(user_pseudo_id, session_id)`.

### 10.4 30-minute session cutoff

Hard-coded by GA4. Multi-session paid-checkout deliberation (see gate Monday,
subscribe Thursday) is invisible to session-scoped joins. Use user-scoped
attribution (§7.4, §9.5) for anything paid.

### 10.5 `form_submission` vs `form_submission_success`

On `np_modal_checkout_interaction`, `form_submission` is the *attempt* and
`form_submission_success` is the *completion*. In the sample, 60 attempts →
56 completions (~7% fail at payment). Older analyses may have treated
`form_submission` as the completion signal — they over-count by the failure
rate.

### 10.6 `user_pseudo_id` is cookie-scoped

A reader who clears cookies, switches browsers, or opens an incognito window
looks like a different user. Cross-device / cross-browser unification
requires `user_id` (only present on authenticated sessions — low coverage).

### 10.7 Intraday vs finalized tables

`events_intraday_YYYYMMDD` for the current day is partial and revised
continuously. Exclude today's date from long-window queries, or include with
the understanding that the count will change later.

### 10.8 Event params rely on the tag firing correctly

Missing params aren't always a data-collection bug — they may reflect the
literal site state. `gate_post_id` missing on a conversion event often means
the user converted on a page where the gate tag wasn't loaded (a follow-up
page, a subscribe-only URL). Before blaming the tag, check whether the
missing events are concentrated on specific pages.

---

## Appendix A: Baseline numbers (test property, 30-day window)

From `customer-analytics-293121.analytics_400107557` over
`20260322`–`20260421`. **Illustrative, not universal** — your property's
numbers will differ.

| Metric | Value |
|---|---|
| Gate impressions | 70,429 |
| Gate `form_submission` (all `action_type`s) | 5,590 |
| Gate dismissals | 3,298 |
| Completed registrations | 575 |
| Completed logins | 2,103 |
| Completed newsletter signups | 258 |
| Completed checkouts (`form_submission_success`) | 56 |
| Checkout attempts (`form_submission`) | 60 |
| Distinct gated sessions | 56,979 |
| Registrations in a gated session (any order) | 492 (91% of all regs) |
| Checkouts in a gated session (any order) | 7 (13% of `form_submission_success`) |

The session-scoping recovery rate on registrations (91%) is strong — most
registrations happen in-flow with the gate. The recovery on checkouts (13%)
is weak because paid deliberation spans multiple GA4 sessions; this is the
motivating case for user-scoped attribution (§7.4, §9.5).