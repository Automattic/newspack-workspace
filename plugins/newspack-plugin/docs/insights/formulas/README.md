# BigQuery Formula Conventions

These conventions apply to every formula file in this directory. Documented once here so individual tab files can be terse.

---

## Partition filtering — always include `_TABLE_SUFFIX`

GA4 export writes daily-sharded tables (`events_YYYYMMDD`). Always filter by `_TABLE_SUFFIX` in the WHERE clause:

```sql
FROM `<project>.<dataset>.events_*`
WHERE _TABLE_SUFFIX BETWEEN '20260322' AND '20260421'
```

`_TABLE_SUFFIX` is the substring after `events_`. BigQuery uses this for partition pruning — only the matching daily tables get scanned. Without this filter, every query scans the entire history of the dataset, which is catastrophic for cost.

When templating dates in code, format as `YYYYMMDD` strings (no separators) and regex-validate (`^\d{8}$`) before interpolating into SQL.

---

## `PARAM()` shorthand via temp UDFs

`event_params` is a repeated struct. The native access pattern requires a subquery per key:

```sql
(SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'gate_post_id') AS gate_post_id,
(SELECT value.string_value FROM UNNEST(event_params) WHERE key = 'action') AS action
```

This is verbose. Formula files declare temp UDFs at the top of each query and reference them as a shorthand:

```sql
CREATE TEMP FUNCTION PARAM_STRING(
  params ARRAY<STRUCT<key STRING, value STRUCT<string_value STRING, int_value INT64, float_value FLOAT64, double_value FLOAT64>>>,
  key_name STRING
) AS (
  (SELECT value.string_value FROM UNNEST(params) WHERE key = key_name)
);

CREATE TEMP FUNCTION PARAM_INT(
  params ARRAY<STRUCT<key STRING, value STRUCT<string_value STRING, int_value INT64, float_value FLOAT64, double_value FLOAT64>>>,
  key_name STRING
) AS (
  (SELECT value.int_value FROM UNNEST(params) WHERE key = key_name)
);

CREATE TEMP FUNCTION PARAM_FLOAT(
  params ARRAY<STRUCT<key STRING, value STRUCT<string_value STRING, int_value INT64, float_value FLOAT64, double_value FLOAT64>>>,
  key_name STRING
) AS (
  (SELECT value.double_value FROM UNNEST(params) WHERE key = key_name)
);
```

Then formulas reference them as:

```sql
PARAM_STRING(event_params, 'action') AS action,
PARAM_INT(event_params, 'gate_post_id') AS gate_post_id
```

Pick the right field per param. See [`event-reference.md`](../event-reference.md) for which value type each param uses.

---

## User identity — fallback to `user_pseudo_id`

`user_id` is populated only when the site has called `gtag('set', {user_id: …})`. Coverage is low. Always coalesce to `user_pseudo_id` so anonymous users still cluster correctly:

```sql
COALESCE(user_id, user_pseudo_id) AS user_key
```

**Caveat:** cross-device unification requires `user_id`. Cookie-scoped users (cleared cookies, incognito, different browsers) appear as separate `user_pseudo_id` values even when they're the same human. For user-scoped attribution that crosses devices, accept the lower coverage and join on `user_id` directly — but document the coverage limitation in the metric's footnote.

---

## Session key — concat with `user_pseudo_id`

`ga_session_id` is an int scoped to `user_pseudo_id`. Two different users' sessions can share the same session ID. Always join or group on the combined key:

```sql
CONCAT(
  user_pseudo_id,
  '|',
  CAST(PARAM_INT(event_params, 'ga_session_id') AS STRING)
) AS session_key
```

Never use bare `ga_session_id` for joining.

---

## `NULLIF` for divide-by-zero

Conversion rate denominators can be zero (no impressions in window, no eligible sessions, etc.). Use `SAFE_DIVIDE` and/or `NULLIF` to return NULL instead of erroring:

```sql
SAFE_DIVIDE(conversions, NULLIF(impressions, 0)) AS conversion_rate
```

`SAFE_DIVIDE` alone returns NULL on division by zero. `NULLIF(impressions, 0)` makes the intent explicit and works in `/` expressions too. Belt and suspenders.

---

## `COALESCE` for mixed-type params

Two params in the Newspack schema are stored in multiple `value.*` fields across rows:

- `amount` on `np_modal_checkout_interaction` — `int_value` for whole-dollar amounts, `double_value` for sub-dollar.
- `lists` on `np_newsletter_subscribed` — `int_value` for single-list signups, `string_value` for multi-list (comma-separated).

Always coalesce:

```sql
-- amount
COALESCE(
  PARAM_INT(event_params, 'amount'),
  CAST(PARAM_FLOAT(event_params, 'amount') AS INT64)
) AS amount

-- lists
COALESCE(
  CAST(PARAM_INT(event_params, 'lists') AS STRING),
  PARAM_STRING(event_params, 'lists')
) AS lists
```

---

## Exclude intraday tables for finalized counts

`events_intraday_YYYYMMDD` (current day) is partial and revised continuously. For long-window queries where stable counts matter, exclude today by ending `_TABLE_SUFFIX` at yesterday:

```sql
WHERE _TABLE_SUFFIX BETWEEN
  FORMAT_DATE('%Y%m%d', DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY))
  AND FORMAT_DATE('%Y%m%d', DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY))
```

For real-time-ish views where partial today is acceptable, include both finalized and intraday tables via `events_*` wildcard and end the suffix at today's date. Document in the metric footnote which mode is in use.

---

## `form_submission` vs completion

On `np_modal_checkout_interaction`, `action='form_submission'` is the **intent** — the user clicked submit. The historical `form_submission_success` indicated **completion** but is deprecated (see [`event-reference.md`](../event-reference.md)).

For completion signals, use:

| Conversion type | Completion source |
|---|---|
| Registration | `np_reader_registered` event |
| Login | `np_reader_logged_in` event |
| Newsletter signup | `np_newsletter_subscribed` event |
| Paid checkout | Join `np_modal_checkout_interaction` (intent) with WooCommerce orders in the local DB, matching on `gate_post_id` and a time window (see [open-questions.md](../open-questions.md) for the window default) |

Never treat `form_submission` as a completion signal on its own. It over-counts by the failure rate of the downstream step.

---

## Attribution rules — write the model into the metric

Every non-inline conversion needs an attribution rule. Pick based on the strength-of-signal vs coverage trade-off the metric can tolerate. Four rules, ordered strongest → loosest:

| Rule | Match | When to use |
|---|---|---|
| **A. Inline** | Conversion recorded on the gate event itself (`np_gate_interaction action='form_submission'`) | Always start here for gate-conversion rate; numerator is just an event count |
| **B. Param-tagged** | Completion event carries `gate_post_id` | Registration (90%), login (74%); acceptable for checkout (42%) |
| **C. Session-scoped** | Completion in the same `(user_pseudo_id, ga_session_id)` as a gate-seen | Fills the param-tagging gap for in-visit conversions |
| **D. User-scoped window** | Completion by the same `user_pseudo_id` within N days of a gate-seen | Right model for paid checkout (multi-session deliberation) |

Each metric's formula file states which rule it uses and why. Don't silently mix rules across metrics in the same tab.
