# Tab 4: Gates — Formulas

Reference: see `formulas/README.md` for conventions used throughout (PARAM() shorthand, partition filtering, user_id fallback, etc.). See `../event-reference.md` for the canonical event spec.

## Attribution model

Gates v1 uses **session-scoped Direct attribution** and **same-session-excluded Influenced attribution**. Session key: `CONCAT(user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING))`.

**Direct conversion:** a conversion event (`np_reader_registered` or completed paywall checkout) occurs in the **same GA session** as a qualifying gate impression. Session-scoped via the session key, not via `gate_post_id` on the conversion event.

**Influenced conversion:** a conversion event occurs in a **later session** than a qualifying gate impression, within the lookback window (7 days for free, 14 days for paid). Same-session is excluded to keep Direct and Influenced non-overlapping.

**Per-gate attribution** (Performance by gate table): the gate credited is the **last gate impression in the conversion session**.

Identifying "qualifying" gate impressions per conversion type: see the per-section query for the filtering logic. For paywall-capable identification specifically, see "Open items" at the bottom of this file.

## Section: Gate exposure

### Total Gate Impressions (selected period)

What it measures: Count of every gate-impression event in the window.

- Numerator: Events where `event_name = 'np_gate_interaction'` AND `action = 'seen'`
- Denominator: N/A (scorecard, not a rate)

```sql
SELECT COUNT(*) AS gate_impressions
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_gate_interaction'
  AND PARAM('action') = 'seen'
```

Notes: `_TABLE_SUFFIX` should be `YYYYMMDD` format. Confirm window with `event_date >= @start_date AND event_date <= @end_date` as belt-and-suspenders.

### Unique Readers Who Saw a Gate

- Numerator: Distinct users where they saw a gate

```sql
SELECT COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_gate_viewers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'np_gate_interaction'
  AND PARAM('action') = 'seen'
```

Notes: COALESCE for user_id fallback because anonymous users only have `user_pseudo_id`. Unique users, not unique sessions.

### Avg Gate Exposures per Reader

- Numerator: Total gate impressions
- Denominator: Unique gate viewers

```sql
WITH exposures AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    COUNT(*) AS exposure_count
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_gate_interaction'
    AND PARAM('action') = 'seen'
  GROUP BY uid
)
SELECT
  SUM(exposure_count) / COUNT(*) AS avg_exposures_per_reader
FROM exposures
```

Notes: The `exposures` CTE shape is reused by the distribution table below.

### % of Sessions With a Gate Trigger

- Numerator: Distinct sessions with at least one `np_gate_interaction` (any action)
- Denominator: Distinct sessions total

```sql
WITH session_keys AS (
  SELECT
    CONCAT(user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING)) AS session_key,
    MAX(IF(event_name = 'np_gate_interaction', 1, 0)) AS had_gate
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  GROUP BY session_key
)
SELECT
  SUM(had_gate) / COUNT(*) AS pct_sessions_with_gate
FROM session_keys
```

Notes: `ga_session_id` is NOT globally unique on its own; always concatenate with `user_pseudo_id`.

## Section: Gate conversion (Direct)

### Regwall Conversion Rate (Direct)

Session-scoped: sessions containing a regwall gate impression that were followed by a registration in the same session, divided by all sessions containing a regwall gate impression.

- Numerator: distinct sessions where a regwall gate impression was followed by a registration in the same session
- Denominator: total sessions with a regwall gate impression

```sql
WITH regwall_sessions AS (
  SELECT
    user_pseudo_id,
    CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key='ga_session_id') AS STRING) AS session_id,
    MIN(event_timestamp) AS first_impression_ts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_gate_interaction'
    AND PARAM('action') = 'seen'
    AND (PARAM('gate_has_registration_block') = 'yes'
         OR PARAM('gate_has_registration_link') = 'yes')
  GROUP BY user_pseudo_id, session_id
),
registrations_in_session AS (
  SELECT DISTINCT
    e.user_pseudo_id,
    CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) AS session_id
  FROM `{project}.{dataset}.events_*` e
  INNER JOIN regwall_sessions r
    ON r.user_pseudo_id = e.user_pseudo_id
    AND CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) = r.session_id
    AND e.event_timestamp >= r.first_impression_ts
  WHERE e._TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND e.event_name = 'np_reader_registered'
)
SELECT
  COUNT(*) FROM registrations_in_session
    /
  NULLIF((SELECT COUNT(*) FROM regwall_sessions), 0)
  AS regwall_conversion_rate_direct
```

Notes:
- Publisher-configuration-agnostic at the gate-button level: doesn't matter if the gate had an embedded form or a registration-link button. Both fire `gate_has_registration_block` or `gate_has_registration_link` on the impression event.
- The `gate_post_id IS NOT NULL` filter on the conversion event is removed under session-scoped attribution. Same-session is the qualifier.
- `gate_has_registration_link` is NOT in the public help doc but IS a real parameter — see `../event-reference.md` for provenance.
- `yes`/`no` are strings, not booleans.
- `NULLIF(..., 0)` prevents division by zero from erroring; returns NULL in no-data periods.

### Paywall Conversion Rate (Direct) — completed checkout

Session-scoped: sessions containing a paywall-capable impression that produced a completed Woo order in the same session.

- Numerator: distinct sessions where a paywall-capable impression was followed by a `np_modal_checkout_interaction(form_submission, checkout_button)` event AND a Woo order completed within 30 minutes of the attempt
- Denominator: total sessions with a paywall-capable impression

```sql
WITH paywall_sessions AS (
  -- Sessions with a paywall-capable impression.
  -- v1: param-based identification (Option A in Open items below).
  -- TODO v1.1 Option B: replace this CTE with server-side classification post-query.
  -- TODO v1.1 Option C: drop the param filter; any gate impression in a session that produced
  --                    a paywall conversion qualifies.
  SELECT
    user_pseudo_id,
    CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key='ga_session_id') AS STRING) AS session_id,
    MIN(event_timestamp) AS first_impression_ts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_gate_interaction'
    AND PARAM('action') = 'seen'
    AND PARAM('gate_has_checkout_button') = 'yes'
    -- v1.1 expansion: OR PARAM('gate_has_checkout_link') = 'yes' once param exists
  GROUP BY user_pseudo_id, session_id
),
checkout_attempts_in_session AS (
  SELECT DISTINCT
    e.user_pseudo_id,
    CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) AS session_id,
    e.event_timestamp AS attempt_ts
  FROM `{project}.{dataset}.events_*` e
  INNER JOIN paywall_sessions p
    ON p.user_pseudo_id = e.user_pseudo_id
    AND CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) = p.session_id
    AND e.event_timestamp >= p.first_impression_ts
  WHERE e._TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND e.event_name = 'np_modal_checkout_interaction'
    AND PARAM('action') = 'form_submission'
    AND PARAM('action_type') = 'checkout_button'
)
SELECT user_pseudo_id, session_id, attempt_ts FROM checkout_attempts_in_session
-- Then in PHP: filter by completed Woo order within 30-min window of attempt_ts.
-- Rate = (sessions with a Woo-confirmed completion) / (count of paywall_sessions).
```

Then in PHP, filter the BQ `checkout_attempts_in_session` rows by which UIDs have a completed Woo order within a 30-minute window of `attempt_ts`:

```sql
-- Local Woo query, parameterized by UID list from BQ
SELECT DISTINCT customer_id
FROM wp_wc_orders
WHERE customer_id IN (:bq_uids)
  AND status IN ('completed', 'processing')
  AND date_created_gmt BETWEEN :event_ts_min AND :event_ts_max_plus_30min
```

Notes:
- `form_submission_success` was deprecated — see `../event-reference.md`. To detect success, match the BQ attempt session to a Woo completion in the 30-minute window.
- The 30-minute window is the default; tunable via constant. Worth validating against production data once available — see `../open-questions.md`.
- This metric requires BQ + local Woo combined. Flag for engineering: most architecturally complex metric in v1.
- Currency: assume single-currency per publisher for v1. Multi-currency degrades gracefully with footnote flag.
- Paywall-capable denominator is param-based in v1 (Option A in Open items). Link-style paywall gates without `gate_has_checkout_button='yes'` are missing from the denominator AND from any session that produces an attempt — known v1 limitation. Resolution path: Option B (server-side classification) in v1.1.

### User-level Direct conversion (deprecated)

Removed under session-scoped attribution. Session-scoped naturally dedupes (one session per gate impression, one conversion per session counted at most once). If user-level rollup becomes desired separately, compute `COUNT(DISTINCT user_pseudo_id)` instead of `COUNT(*)` on the session CTEs above.

## Section: Gate conversion (Influenced)

Influenced asks: of users who eventually registered/subscribed in the window, what % had been exposed to a gate **in a prior session** within the lookback period?

Direct measures "same-session: gate impression and conversion share a GA session." Influenced measures "cross-session: gate impression in an earlier session, conversion in a later session within N days." Same-session matches are excluded from Influenced — those belong to Direct, and the two definitions are mutually exclusive.

Influenced is user-level only — the question "what fraction of converters were influenced" is inherently a user-count question.

### Regwall Conversion Rate (Influenced, 7d lookback)

- Numerator: Distinct users who registered in the window AND had ≥1 `np_gate_interaction(seen)` with a registration block/link within `@influenced_window_days` BEFORE their registration
- Denominator: Distinct users who saw a gate with a registration block/link in the window (extended by lookback)

```sql
WITH all_registrations AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key='ga_session_id') AS STRING) AS session_id,
    event_timestamp AS reg_timestamp
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_reader_registered'
),
gate_exposures_extended AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key='ga_session_id') AS STRING) AS session_id,
    event_timestamp AS gate_timestamp
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN
    FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @start_date), INTERVAL @influenced_window_days DAY))
    AND @end_date
    AND event_name = 'np_gate_interaction'
    AND PARAM('action') = 'seen'
    AND (PARAM('gate_has_registration_block') = 'yes'
         OR PARAM('gate_has_registration_link') = 'yes')
),
influenced_registrations AS (
  SELECT DISTINCT r.uid
  FROM all_registrations r
  INNER JOIN gate_exposures_extended g
    ON r.uid = g.uid
    AND g.gate_timestamp < r.reg_timestamp
    AND g.gate_timestamp >= TIMESTAMP_SUB(TIMESTAMP_MICROS(r.reg_timestamp), INTERVAL @influenced_window_days DAY)
    AND g.session_id != r.session_id  -- exclude same-session (those are Direct)
),
total_gate_viewers AS (
  SELECT COUNT(DISTINCT uid) AS denominator
  FROM gate_exposures_extended
)
SELECT
  (SELECT COUNT(*) FROM influenced_registrations)
    /
  NULLIF((SELECT denominator FROM total_gate_viewers), 0)
  AS regwall_conversion_influenced
```

Notes:
- Cross-session only. Same-session conversions count toward Direct, not Influenced. The two definitions are mutually exclusive.
- `gate_exposures_extended` CTE pulls gate impressions from `@influenced_window_days` BEFORE window start.
- Higher bytes-processed cost than Direct because of the wider scan. Cost guardrail's dry-run estimate matters more here.
- `@influenced_window_days` = 7 for registration (free conversion).

### Paywall Conversion Rate (Influenced, 14d lookback)

Same shape as Regwall Influenced but:
- Numerator filters on completed Woo subscription (same Woo join as Direct paywall)
- Denominator filters gate exposures on `gate_has_checkout_button = 'yes'`
- Cross-session only — apply the same `g.session_id != r.session_id` exclusion in the `influenced_subscriptions` CTE so same-session conversions stay attributed to Direct
- `@influenced_window_days` = 14 (paid conversion)

Notes: cross-session only. Same-session conversions count toward Direct, not Influenced.

## Section: Revenue from gates

### Total Revenue from Paywall (Direct)

- Sum of completed Woo order totals from sessions where a paywall-capable impression preceded a checkout attempt in the same session.

```sql
-- BQ side: session-scoped paywall checkout attempts.
-- Uses the same `paywall_sessions` + `checkout_attempts_in_session` CTE shape
-- as the Direct conversion rate query above; produces (user_pseudo_id,
-- session_id, attempt_ts) tuples for the PHP-side Woo join.
WITH paywall_sessions AS (
  SELECT
    user_pseudo_id,
    CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key='ga_session_id') AS STRING) AS session_id,
    MIN(event_timestamp) AS first_impression_ts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_gate_interaction'
    AND PARAM('action') = 'seen'
    AND PARAM('gate_has_checkout_button') = 'yes'
  GROUP BY user_pseudo_id, session_id
),
checkout_attempts_in_session AS (
  SELECT DISTINCT
    e.user_pseudo_id,
    CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) AS session_id,
    e.event_timestamp AS attempt_ts
  FROM `{project}.{dataset}.events_*` e
  INNER JOIN paywall_sessions p
    ON p.user_pseudo_id = e.user_pseudo_id
    AND CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) = p.session_id
    AND e.event_timestamp >= p.first_impression_ts
  WHERE e._TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND e.event_name = 'np_modal_checkout_interaction'
    AND PARAM('action') = 'form_submission'
    AND PARAM('action_type') = 'checkout_button'
)
SELECT user_pseudo_id, session_id, attempt_ts FROM checkout_attempts_in_session
-- Then in PHP: for each (uid, attempt_ts), find the completed Woo order within
-- the 30-min window and sum its `total_amount`. Use actual Woo totals, not the
-- BQ `amount` event param.
```

Notes:
- Use actual Woo order totals, NOT BQ `attempted_amount`. Pricing can change between attempt and completion (coupons, taxes, currency conversion). BQ's `amount` is "intended"; Woo is "actual."
- `amount` is mixed-type (int + double); the PARAM_FLOAT helper must COALESCE both casts.
- BQ `amount` is fallback only — used when Woo data is unavailable, with footnote flag.
- The "gate-tagged" filter on the BQ side is removed; same-session qualification (paywall_sessions JOIN) replaces it.

### Total Revenue from Paywall (Influenced, 14d)

Same as Direct revenue but the qualifying Woo orders are those from users who had a paywall-capable impression in an **earlier session** within the 14-day lookback (apply the `session_id != session_id` cross-session-only exclusion). Pattern is clear from Direct + Influenced above.

### Avg Revenue per Paywall Conversion

```
total_revenue / NULLIF(conversion_count, 0)
```

Plain arithmetic, computed at display time from the two previous metrics.

## Section: Funnel

### Funnel: Gate Impression → Engagement → Conversion (rolled up)

Three-stage funnel, distinct user counts. Stage 3 is **session-scoped**: a conversion counts only if it happened in a session that included a Stage 1 gate impression.

```sql
WITH
  stage1_sessions AS (
    SELECT DISTINCT
      user_pseudo_id,
      CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key='ga_session_id') AS STRING) AS session_id
    FROM `{project}.{dataset}.events_*`
    WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND event_name = 'np_gate_interaction'
      AND PARAM('action') = 'seen'
  ),
  stage2_engagement AS (
    SELECT DISTINCT
      user_pseudo_id,
      CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key='ga_session_id') AS STRING) AS session_id
    FROM `{project}.{dataset}.events_*`
    WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND event_name = 'np_gate_interaction'
      AND PARAM('action') = 'form_submission'
  ),
  stage3_conversion AS (
    SELECT DISTINCT
      e.user_pseudo_id,
      CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) AS session_id
    FROM `{project}.{dataset}.events_*` e
    INNER JOIN stage1_sessions s
      ON s.user_pseudo_id = e.user_pseudo_id
      AND CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) = s.session_id
    WHERE e._TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND (e.event_name = 'np_reader_registered'
           OR (e.event_name = 'np_modal_checkout_interaction'
               AND PARAM('action') = 'form_submission'))
  )
SELECT
  (SELECT COUNT(DISTINCT user_pseudo_id) FROM stage1_sessions) AS step_1_impression,
  (SELECT COUNT(DISTINCT user_pseudo_id) FROM stage2_engagement) AS step_2_engagement,
  (SELECT COUNT(DISTINCT user_pseudo_id) FROM stage3_conversion) AS step_3_conversion
```

Notes:
- "Engagement" = any `form_submission` action on a gate, regardless of success.
- Stage 3 counts distinct users with a same-session conversion after a same-session gate impression. No `gate_post_id` filter on the conversion event; session-scoped attribution captures both embedded-checkout and link-style paywall conversions.
- Stage 3 unions registrations + paywall attempts. If you want separate funnels per intent, produce two funnels. Recommendation: one rolled-up funnel for the Gates tab overview; Conversion Journey tab (Tab 3) has separate funnels per journey type.

## Section: Performance breakdown

### Table: Performance by Gate

Row per gate post, with impressions and conversion rates. Per-gate conversion credit goes to the **last gate impression in the conversion session**.

```sql
WITH
  gate_impressions AS (
    SELECT
      user_pseudo_id,
      CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key='ga_session_id') AS STRING) AS session_id,
      event_timestamp,
      PARAM('gate_post_id') AS gate_post_id,
      PARAM('gate_has_registration_block') AS has_reg_block,
      PARAM('gate_has_registration_link') AS has_reg_link,
      PARAM('gate_has_checkout_button') AS has_checkout_button
    FROM `{project}.{dataset}.events_*`
    WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND event_name = 'np_gate_interaction'
      AND PARAM('action') = 'seen'
      AND PARAM('gate_post_id') IS NOT NULL
  ),
  session_last_gate AS (
    -- Last gate impression per session (the gate credited for any
    -- same-session conversion).
    SELECT
      user_pseudo_id,
      session_id,
      ARRAY_AGG(gate_post_id ORDER BY event_timestamp DESC LIMIT 1)[OFFSET(0)] AS last_gate_post_id,
      ARRAY_AGG(STRUCT(has_reg_block, has_reg_link, has_checkout_button) ORDER BY event_timestamp DESC LIMIT 1)[OFFSET(0)] AS last_gate_caps,
      MAX(event_timestamp) AS last_impression_ts
    FROM gate_impressions
    GROUP BY user_pseudo_id, session_id
  ),
  per_gate_impressions AS (
    SELECT
      gate_post_id,
      COUNT(*) AS impressions,
      COUNT(DISTINCT user_pseudo_id) AS unique_viewers,
      COUNTIF(has_reg_block = 'yes' OR has_reg_link = 'yes') AS reg_impressions,
      COUNTIF(has_checkout_button = 'yes') AS checkout_impressions
    FROM gate_impressions
    GROUP BY gate_post_id
  ),
  registrations_attributed AS (
    SELECT
      slg.last_gate_post_id AS gate_post_id,
      COUNT(*) AS registrations
    FROM session_last_gate slg
    INNER JOIN `{project}.{dataset}.events_*` e
      ON e.user_pseudo_id = slg.user_pseudo_id
      AND CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) = slg.session_id
      AND e.event_timestamp >= slg.last_impression_ts
    WHERE e._TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND e.event_name = 'np_reader_registered'
    GROUP BY slg.last_gate_post_id
  ),
  paywall_attempts_attributed AS (
    SELECT
      slg.last_gate_post_id AS gate_post_id,
      COUNT(*) AS paywall_attempts
    FROM session_last_gate slg
    INNER JOIN `{project}.{dataset}.events_*` e
      ON e.user_pseudo_id = slg.user_pseudo_id
      AND CAST((SELECT value.int_value FROM UNNEST(e.event_params) WHERE key='ga_session_id') AS STRING) = slg.session_id
      AND e.event_timestamp >= slg.last_impression_ts
    WHERE e._TABLE_SUFFIX BETWEEN @start_date AND @end_date
      AND e.event_name = 'np_modal_checkout_interaction'
      AND PARAM('action') = 'form_submission'
      AND PARAM('action_type') = 'checkout_button'
    GROUP BY slg.last_gate_post_id
  )
SELECT
  i.gate_post_id,
  i.impressions,
  i.unique_viewers,
  COALESCE(r.registrations, 0) AS registrations,
  SAFE_DIVIDE(r.registrations, i.reg_impressions) AS regwall_conversion_rate,
  COALESCE(p.paywall_attempts, 0) AS paywall_attempts,
  SAFE_DIVIDE(p.paywall_attempts, i.checkout_impressions) AS paywall_attempt_rate
FROM per_gate_impressions i
LEFT JOIN registrations_attributed r USING (gate_post_id)
LEFT JOIN paywall_attempts_attributed p USING (gate_post_id)
ORDER BY i.impressions DESC
LIMIT 50
```

Notes:
- Per-gate conversion credit goes to the last gate impression in the conversion session. If a reader saw three gates in one session and then converted, only the third gate gets the credit.
- Conversion rate denominator stays at "impressions that offered the conversion type" (preserves intuitive per-gate rates). Link-style paywall gates with `gate_has_checkout_button='no'` will show paywall conversion rate as null (em-dash in the UI) until either Option B (server-side classification) or a `gate_has_checkout_link` param exists. Documented as a known v1 limitation in Open items below.
- `SAFE_DIVIDE` returns NULL instead of erroring on division by zero.
- `gate_post_id` is the WP post ID. Metric class enriches with gate name by querying `wp_posts` for the title server-side.
- LIMIT 50 guardrails against very large gate counts. Most publishers have <20 gates.

### Table: Performance by Content Type

Same shape, but grouped by post category. `categories` is a custom event param (comma-separated), but parsing arrays in BQ is awkward. Cleanest path: enrich each `gate_post_id` row with its primary category server-side after the SQL returns, then group in PHP.

## Section: Exposure-to-conversion distribution

### Table: Gate Exposures Before Conversion (buckets)

For each converter, count their gate impressions before their first conversion event. Bucket into 1, 2, 3-5, 6+.

```sql
WITH conversions AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    MIN(event_timestamp) AS first_conv_ts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND (event_name = 'np_reader_registered'
      OR (event_name = 'np_modal_checkout_interaction'
          AND PARAM('action') = 'form_submission'))
  GROUP BY uid
),
exposures_before_conv AS (
  SELECT
    c.uid,
    COUNTIF(
      e.event_name = 'np_gate_interaction'
      AND PARAM_FROM(e.event_params, 'action') = 'seen'
      AND e.event_timestamp < c.first_conv_ts
    ) AS exposures
  FROM conversions c
  JOIN `{project}.{dataset}.events_*` e
    ON COALESCE(e.user_id, e.user_pseudo_id) = c.uid
  WHERE e._TABLE_SUFFIX BETWEEN
    FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @start_date), INTERVAL 30 DAY))
    AND @end_date
  GROUP BY c.uid, c.first_conv_ts
),
bucketed AS (
  SELECT
    CASE
      WHEN exposures = 1 THEN '1'
      WHEN exposures = 2 THEN '2'
      WHEN exposures BETWEEN 3 AND 5 THEN '3-5'
      WHEN exposures >= 6 THEN '6+'
    END AS bucket,
    COUNT(*) AS converters_in_bucket
  FROM exposures_before_conv
  WHERE exposures > 0
  GROUP BY bucket
)
SELECT
  bucket,
  converters_in_bucket,
  SAFE_DIVIDE(converters_in_bucket, SUM(converters_in_bucket) OVER ()) AS pct_of_converters
FROM bucketed
ORDER BY
  CASE bucket WHEN '1' THEN 1 WHEN '2' THEN 2 WHEN '3-5' THEN 3 WHEN '6+' THEN 4 END
```

Notes:
- Lookback for "exposures before conversion" is 30 days back, regardless of session boundaries. Distribution captures both same-session and cross-session prior exposures (cumulative gate touches preceding the conversion, not just within the conversion session).
- Under session-scoped attribution, the conversion filter drops `gate_post_id IS NOT NULL` — any registration or paywall attempt qualifies if there was a prior gate impression in the lookback window.
- `WHERE exposures > 0` excludes users who converted without ever seeing a gate.

## Open items specific to Tab 4

1. **Paywall-capable gate identification.** Under session-scoped attribution, the paywall conversion rate denominator needs to identify which gate impressions offered paid conversion. Three options:
   - **Option A (param-based):** filter on `gate_has_checkout_button='yes'`. Misses link-style paywall buttons (gate_post_id present, but no `gate_has_checkout_button='yes'` param), so both numerator and denominator under-count link-style gates. NOT a real fix.
   - **Option B (server-side classification):** orchestrator looks up each `gate_post_id` in `wp_posts` / Newspack popups config to determine if the gate is configured as a paywall. Most correct; requires post-processing logic in the metric orchestrator. RECOMMENDED for v1.1.
   - **Option C (behavioral):** any gate that appeared in a session with a paywall conversion is treated as paywall-capable for that session. Simplest, publisher-config-agnostic; accepts that a registration-gate impression that happened to precede a subscription will get attribution credit. Acceptable as a v1 implementation.

   **v1 implementation:** Option A on the BQ side (preserves intuitive per-gate rates); document the link-style paywall gap. **v1.1:** Option B in orchestrator post-processing.
2. **Paywall completion match window:** defaults to 30 minutes. Needs validation against production data. See `../open-questions.md`.
3. **Multi-currency handling:** v2 problem. v1 degrades gracefully with a footnote.
4. **Author/category breakdowns from event params:** parsed server-side after SQL returns rather than in BQ, due to comma-separated array shape.
5. **Cross-session same-user identification:** session-scoped Direct relies on the GA session boundary. For users who span multiple sessions on the same day (e.g., site visit, leave, return), each session is independent. This is the GA-standard interpretation. If publishers want a "visit" definition different from GA's session, that's a v2 conversation.

## Cross-references

- Event reference: `../event-reference.md`
- BQ conventions: `./README.md`
- Architecture: `../architecture.md`
- Open questions: `../open-questions.md`
