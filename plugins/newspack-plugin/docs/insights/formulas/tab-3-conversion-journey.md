# Tab 3: Conversion Journey — Formulas

Reference: see `formulas/README.md` for conventions. See `./subscription-donation-schema.md` for HPOS/legacy storage detection and donation product classification. See `../event-reference.md` for global event params (`logged_in`, `is_subscriber`, `is_donor`, `is_newsletter_subscriber`).

## Scope

Tab 3 answers "How are people moving from stranger to supporter?" — the cross-cutting journey view that ties reach (Tab 1) to conversion (Tabs 4, 5, 6, 7).

Unlike single-source tabs (Gates, Prompts) that have their own Direct/Influenced attribution, Tab 3 aggregates ACROSS sources. The funnels and conversion rates here include readers who converted through any gate, any prompt, or directly via a standalone form.

This is the most strategic tab — publishers spend the most analytical time here. It's also the most expensive computationally. Cohort retention in particular needs Action Scheduler pre-warm (NPPD-1606); v1 should refresh it weekly, not per-request.

## Conventions specific to this tab

- **Reader identity:** `COALESCE(user_id, user_pseudo_id)` throughout.
- **"Converted" definition:** a reader has registered (`np_reader_registered` event), subscribed (Woo subscription + non-donation product), or donated (Woo order + donation product). Each tracked separately.
- **Cross-tab Influenced:** the Influenced metrics here come from `np_gate_interaction(seen)` OR `np_prompt_interaction(seen)` within the standard lookback windows (7d free, 14d paid). User-level only, per the family pattern.
- **BQ + local Woo joins:** funnels through registration are BQ-only. Funnels through subscription/donation require joining BQ event data to local Woo orders. Both flavors appear on this tab.

## Section: The headline funnel

### Reader Lifecycle Funnel (in window)

The marquee chart. Five-stage funnel showing distinct user counts at each lifecycle stage in the window.

Stages:
1. **Anonymous reader** — distinct users with any event
2. **Engaged reader** — distinct users with `session_engaged = 1` events
3. **Registered reader** — distinct users with at least one event where `logged_in = 'yes'` OR who fired `np_reader_registered` in window
4. **Newsletter subscriber** — distinct users with `is_newsletter_subscriber = 'yes'` on any event
5. **Subscriber OR donor** — distinct users with `is_subscriber = 'yes'` OR `is_donor = 'yes'` on any event

```sql
WITH stages AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    MAX(1) AS is_anonymous,
    MAX(IF(PARAM('session_engaged') = '1', 1, 0)) AS is_engaged,
    MAX(IF(PARAM('logged_in') = 'yes' OR event_name = 'np_reader_registered', 1, 0)) AS is_registered,
    MAX(IF(PARAM('is_newsletter_subscriber') = 'yes', 1, 0)) AS is_newsletter_subscriber,
    MAX(IF(PARAM('is_subscriber') = 'yes' OR PARAM('is_donor') = 'yes', 1, 0)) AS is_supporter
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  GROUP BY uid
)
SELECT
  SUM(is_anonymous) AS step_1_anonymous,
  SUM(is_engaged) AS step_2_engaged,
  SUM(is_registered) AS step_3_registered,
  SUM(is_newsletter_subscriber) AS step_4_newsletter_subscriber,
  SUM(is_supporter) AS step_5_supporter
FROM stages;
```

Notes:
- The stages are NESTED — a "supporter" is also "registered" is also "engaged" is also "anonymous." Each row in `stages` contributes to ALL applicable buckets, not just the topmost. This is intentional: the funnel widget shows "of N anonymous, M became engaged, etc."
- Use the Funnel component with `compactMode={true}` for the 5-step layout. Each step's percentage is calculated against step 1 (anonymous), not the prior step. Component handles both views.

## Section: Per-journey funnels

Three smaller funnels, side-by-side or stacked depending on layout. Each is a focused conversion path.

### Funnel: Anonymous → Registered

The free-conversion path.

```sql
WITH registration_attribution AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    -- Was there a gate seen before registration?
    MAX(IF(event_name = 'np_gate_interaction'
           AND PARAM('action') = 'seen'
           AND (PARAM('gate_has_registration_block') = 'yes'
                OR PARAM('gate_has_registration_link') = 'yes'),
           1, 0)) AS saw_gate,
    -- Was there a registration-intent prompt seen before registration?
    MAX(IF(event_name = 'np_prompt_interaction'
           AND PARAM('action') = 'seen'
           AND PARAM('action_type') = 'registration'
           AND PARAM('prompt_has_registration_block') = 'yes',
           1, 0)) AS saw_prompt,
    -- Did they register?
    MAX(IF(event_name = 'np_reader_registered', 1, 0)) AS registered
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  GROUP BY uid
)
SELECT
  COUNT(*) AS step_1_anonymous,
  SUM(IF(saw_gate = 1 OR saw_prompt = 1, 1, 0)) AS step_2_saw_conversion_surface,
  SUM(registered) AS step_3_registered
FROM registration_attribution;
```

Notes:
- Three-stage funnel: showed up → saw a gate or prompt → registered.
- "Saw conversion surface" combines gate AND prompt impressions to make this funnel readable. Splitting by surface lives in Tabs 4 and 5.

### Funnel: Registered → Subscriber (non-donation)

The paid-upsell path. Requires joining BQ events to local Woo subscriptions.

```sql
-- BQ side: registered readers in window
WITH registered_in_window AS (
  SELECT DISTINCT COALESCE(user_id, user_pseudo_id) AS uid
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_reader_registered'
),
-- BQ side: of those registered, who saw a subscription-intent prompt or paywall?
saw_subscription_surface AS (
  SELECT DISTINCT COALESCE(user_id, user_pseudo_id) AS uid
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND (
      (event_name = 'np_gate_interaction'
       AND PARAM('action') = 'seen'
       AND PARAM('gate_has_checkout_button') = 'yes')
      OR
      (event_name = 'np_prompt_interaction'
       AND PARAM('action') = 'seen'
       AND PARAM('action_type') = 'registration'
       AND PARAM('prompt_has_registration_block') = 'yes')
    )
)
SELECT
  (SELECT COUNT(*) FROM registered_in_window) AS step_1_registered,
  (SELECT COUNT(*) FROM saw_subscription_surface) AS step_2_saw_conversion_surface,
  -- Step 3 is "became subscriber" — UIDs from steps 1 OR 2 that have a non-donation subscription in Woo
  -- This requires PHP-side join with Woo data; pseudo-SQL below for reference:
  -- (SELECT COUNT(DISTINCT customer_id) FROM {prefix}wc_orders
  --   WHERE type = 'shop_subscription' AND status = 'wc-active'
  --   AND customer_id IN (:uids_from_registered_in_window)
  --   AND id NOT IN (donation_subscription_ids))
  NULL AS step_3_became_subscriber;
```

Notes:
- Step 3 requires `:uids_from_registered_in_window` from BQ as input to a Woo query. Either:
  - PHP layer fetches UIDs from BQ, then queries Woo with them
  - OR pass Woo customer_ids to BQ as a parameter list (less elegant but avoids round-trip)
- "Subscriber" excludes donation subscriptions per the Tab 6 / Tab 7 separation.
- Same shape works for Registered → Donor: substitute donation-intent surfaces in step 2 and donation orders in step 3.

### Funnel: Registered → Donor

Same structure as Registered → Subscriber but with donation-intent surfaces and donation orders. Pattern is identical; not repeating SQL.

### Funnel: Subscriber → Donor (cross-upsell)

For publishers who run both: of paid subscribers, how many also donate?

```sql
-- All-Woo query (no BQ); just intersect customer sets
WITH active_subscribers AS (
  SELECT DISTINCT customer_id FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_subscription'
    AND o.status = 'wc-active'
    AND opl.product_id NOT IN (:donation_product_ids)
),
also_donors AS (
  SELECT DISTINCT s.customer_id
  FROM active_subscribers s
  JOIN {prefix}wc_orders o ON o.customer_id = s.customer_id
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND o.status IN ('wc-completed', 'wc-processing')
    AND opl.product_id IN (:donation_product_ids)
    AND o.date_created_gmt BETWEEN :start AND :end
)
SELECT
  (SELECT COUNT(*) FROM active_subscribers) AS step_1_subscriber,
  (SELECT COUNT(*) FROM also_donors) AS step_2_also_donor;
```

Notes:
- Hide this funnel when the publisher has fewer than 50 active subscribers OR fewer than 50 active donors. Below that threshold the funnel is noise.
- Per the Tab 6/7 caveats: this funnel inherits the Ambassador-style classification problem (NPPD-1619). A publisher whose "donation tier" subscribers are misclassified as subscribers will see them in step 1 instead of step 2.

## Section: Conversion source attribution

### Source Mix: New Registrations (PieChart)

Of new registrations in the window, what fraction came via gate, prompt, direct form, or untagged?

```sql
WITH registrations AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    event_timestamp AS reg_ts,
    PARAM('gate_post_id') AS gate_post_id,
    PARAM('newspack_popup_id') AS popup_id
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_reader_registered'
)
SELECT
  CASE
    WHEN gate_post_id IS NOT NULL THEN 'gate'
    WHEN popup_id IS NOT NULL THEN 'prompt'
    ELSE 'direct'
  END AS source,
  COUNT(*) AS registrations
FROM registrations
GROUP BY source
ORDER BY registrations DESC;
```

Notes:
- `gate_post_id` and `popup_id` are set on the registration event if it came through one of those surfaces. Direct form submissions (e.g., standalone My Account registration) have neither.
- For the Influenced view (saw a gate/prompt in lookback but converted through a different surface), see "Influenced Attribution" section below.

### Source Mix: New Subscribers (PieChart)

Same shape, with subscription orders.

```sql
-- BQ side: get checkout events that resulted in subscription attempts, tagged by source
WITH subscription_attempts AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    PARAM('gate_post_id') AS gate_post_id,
    PARAM('newspack_popup_id') AS popup_id,
    event_timestamp
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_modal_checkout_interaction'
    AND PARAM('action') = 'form_submission'
    AND PARAM('action_type') = 'checkout_button'
)
-- Then PHP filters by completed Woo non-donation subscription orders within 30 min
-- and aggregates by source classification
```

PHP-side aggregation:
- Filter BQ rows to those with a matching completed Woo subscription order
- Classify each: gate (gate_post_id set) → prompt (popup_id set, no gate) → direct (neither)
- COUNT per source

### Source Mix: New Donors (PieChart)

Same shape, with donation orders. Substitute `action_type = 'donation'` filtering and donation-product Woo filter.

## Section: Time-to-convert cumulative distributions

The cumulative shape replaces v1's original BoxPlot framing. For each metric, compute the empirical CDF: for each day N within the cohort's lookback window, what fraction of the cohort had converted by day N? LineChart consumes the result directly — one row per (day, cumulative_pct) point per series.

### Time-to-Register Cumulative Distribution

```sql
WITH first_sessions AS (
  SELECT
    user_pseudo_id,
    MIN(event_timestamp) AS first_session_ts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN
    FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @start_date), INTERVAL 90 DAY))
    AND @end_date
  GROUP BY user_pseudo_id
),
registrations AS (
  SELECT
    user_pseudo_id,
    event_timestamp AS reg_ts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_reader_registered'
),
days_diff AS (
  SELECT TIMESTAMP_DIFF(TIMESTAMP_MICROS(r.reg_ts), TIMESTAMP_MICROS(fs.first_session_ts), DAY) AS days
  FROM registrations r
  JOIN first_sessions fs USING (user_pseudo_id)
  WHERE TIMESTAMP_DIFF(TIMESTAMP_MICROS(r.reg_ts), TIMESTAMP_MICROS(fs.first_session_ts), DAY) BETWEEN 0 AND 90
),
total AS (SELECT COUNT(*) AS total_count FROM days_diff),
per_day AS (SELECT days, COUNT(*) AS conversions FROM days_diff GROUP BY days)
SELECT
  days,
  ROUND(SUM(conversions) OVER (ORDER BY days) / (SELECT total_count FROM total), 4) AS cumulative_pct
FROM per_day
ORDER BY days;
```

Notes:
- Returns one row per (day, cumulative_pct) pair. LineChart treats this as a single series.
- 90-day lookback cap matches the spec's truncation. Readers with first session > 90 days ago aren't represented.
- Filter `days >= 0` excludes timestamp-drift anomalies.

### Time-to-Subscribe Cumulative Distribution

Two-step: BQ side gathers per-UID registration timestamps + source attribution; PHP side joins to Woo for first non-donation subscription order date, computes per-source cumulative.

```sql
-- BQ side: registrations in trailing 365 days with source attribution
SELECT
  COALESCE(user_id, user_pseudo_id) AS uid,
  MIN(event_timestamp) AS reg_ts,
  ANY_VALUE(CASE
    WHEN PARAM('gate_post_id') IS NOT NULL THEN 'gate'
    WHEN PARAM('newspack_popup_id') IS NOT NULL THEN 'prompt'
    ELSE 'direct'
  END) AS source
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN
  FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @start_date), INTERVAL 365 DAY))
  AND @end_date
  AND event_name = 'np_reader_registered'
GROUP BY uid;
```

PHP-side completion:
1. For each registered UID, query Woo for first non-donation subscription order date.
2. Compute days_to_subscribe per UID.
3. Truncate at 365 days.
4. Partition by source. Within each partition, sort by days, compute running cumulative_pct.
5. Return three series: `[{ label: 'gate', points: [{day, cumulative_pct}, ...] }, { label: 'prompt', points: [...] }, { label: 'direct', points: [...] }]`.

### Time-to-Donate Cumulative Distribution

Same shape as Time-to-Subscribe, with donation-product Woo filter instead of non-donation subscription. Three series by source.

### Subscriber → Donor Lag Cumulative Distribution

All-Woo. For each customer who subscribed before donating, compute days between first subscription and first donation; partition into single series.

```sql
WITH subscriber_first AS (
  SELECT o.customer_id, MIN(o.date_created_gmt) AS first_sub_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND opl.product_id IN (:non_donation_subscription_product_ids)
    AND o.status IN ('wc-completed', 'wc-processing')
  GROUP BY o.customer_id
),
donor_first AS (
  SELECT o.customer_id, MIN(o.date_created_gmt) AS first_donation_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND opl.product_id IN (:donation_product_ids)
    AND o.status IN ('wc-completed', 'wc-processing')
  GROUP BY o.customer_id
),
days_diff AS (
  SELECT TIMESTAMPDIFF(DAY, sb.first_sub_date, df.first_donation_date) AS days
  FROM subscriber_first sb
  JOIN donor_first df USING (customer_id)
  WHERE df.first_donation_date > sb.first_sub_date
)
-- PHP-side: total count + per-day count → running cumulative_pct
SELECT days FROM days_diff;
```

Compute cumulative_pct in PHP (same pattern as Time-to-Register).

Notes for all four:
- Single-group output shape: `{ points: [{day, cumulative_pct}, ...] }`.
- Multi-group output shape: `{ groups: [{ label, points: [{day, cumulative_pct}, ...] }, ...] }`.
- Section 4.4 is gated at 50 cross-converters per the spec; below that threshold, return `{ visibility: 'hidden', visibility_reason: 'insufficient_data' }`.

## Section: Cohort retention

### Cohort Retention Curve: Registrations → Conversion

For each monthly cohort (readers who first registered in month M), what % had converted to subscriber/donor N months later?

```sql
WITH registration_cohorts AS (
  SELECT
    user_pseudo_id,
    DATE_FORMAT(MIN(PARSE_DATE('%Y%m%d', event_date)), '%Y-%m') AS cohort_month,
    MIN(PARSE_DATE('%Y%m%d', event_date)) AS reg_date
  FROM `{project}.{dataset}.events_*`
  WHERE event_name = 'np_reader_registered'
    AND _TABLE_SUFFIX BETWEEN
      FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @end_date), INTERVAL 365 DAY))
      AND @end_date
  GROUP BY user_pseudo_id
),
cohort_sizes AS (
  SELECT cohort_month, COUNT(*) AS cohort_size
  FROM registration_cohorts
  GROUP BY cohort_month
)
-- BQ returns cohort sizes by month; PHP then queries Woo for converted-by-month per cohort:
SELECT * FROM cohort_sizes ORDER BY cohort_month;
```

PHP-side completion: for each cohort, query Woo to find which UIDs in that cohort have a completed subscription or donation order, group by months-since-registration. Returns a row per (cohort_month, months_since_registration, conversion_count).

LineChart data shape:
```
cohort_month | months_since_registration | retention_rate
2025-12      | 0                         | 0.000
2025-12      | 1                         | 0.087
2025-12      | 2                         | 0.142
...
```

One series per cohort. Reference line at publisher-set conversion target (e.g., 15% at 6 months).

Notes:
- Heavy query. Pre-warm via Action Scheduler. Refresh weekly, not per-page-load.
- For windows < 12 months, drop the oldest cohorts that don't have enough data.
- "Conversion" here = first sub OR donation. Could split into separate cohort charts (one for sub-conversion, one for donor-conversion). v1 ships the combined; v1.1 adds separates.

### Cohort Retention Curve: Subscribers → Retention

For each monthly cohort (readers who first subscribed in month M), what % were still active subscribers N months later?

All-Woo query — no BQ needed.

```sql
WITH new_subscriber_cohorts AS (
  SELECT
    o.customer_id,
    DATE_FORMAT(MIN(o.date_created_gmt), '%Y-%m') AS cohort_month,
    MIN(o.date_created_gmt) AS first_sub_date
  FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND o.status IN ('wc-completed', 'wc-processing')
    AND opl.product_id IN (:non_donation_subscription_product_ids)
    AND o.date_created_gmt >= DATE_SUB(NOW(), INTERVAL 365 DAY)
  GROUP BY o.customer_id
)
SELECT
  c.cohort_month,
  COUNT(*) AS cohort_size,
  -- For each month-since-cohort, count how many are still active
  SUM(CASE
    WHEN EXISTS (
      SELECT 1 FROM {prefix}wc_orders sub
      JOIN {prefix}wc_order_product_lookup opl2 ON opl2.order_id = sub.id
      WHERE sub.type = 'shop_subscription'
        AND sub.status = 'wc-active'
        AND sub.customer_id = c.customer_id
        AND opl2.product_id IN (:non_donation_subscription_product_ids)
    ) THEN 1 ELSE 0
  END) AS still_active_now
FROM new_subscriber_cohorts c
GROUP BY c.cohort_month;
```

For multi-month retention curves, the query above gives the snapshot at "now." For per-month retention back to each cohort, run the same query repeatedly with NOW() replaced by `DATE_ADD(cohort_first_date, INTERVAL N MONTH)`. PHP loop or BQ stored procedure.

Notes:
- Identical pattern works for donor retention (Tab 7 has the donor-specific version).
- Pre-warm; refresh weekly. Same as the BQ-side cohort.

## Section: Conversion rate trends

### Conversion Rate Trends (LineChart, multi-series)

Weekly conversion rates over the trailing 12 weeks (or window-scoped).

```sql
WITH weekly_metrics AS (
  SELECT
    DATE_TRUNC(PARSE_DATE('%Y%m%d', event_date), WEEK) AS week_start,
    COUNT(DISTINCT user_pseudo_id) AS active_readers,
    COUNT(DISTINCT IF(event_name = 'np_reader_registered', user_pseudo_id, NULL)) AS new_registrations,
    COUNT(DISTINCT IF(
      event_name = 'np_modal_checkout_interaction'
      AND PARAM('action') = 'form_submission'
      AND PARAM('action_type') = 'checkout_button',
      user_pseudo_id, NULL
    )) AS subscription_attempts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  GROUP BY week_start
)
SELECT
  week_start,
  SAFE_DIVIDE(new_registrations, active_readers) AS registration_conversion_rate,
  SAFE_DIVIDE(subscription_attempts, new_registrations) AS subscription_attempt_rate
FROM weekly_metrics
ORDER BY week_start;
```

Notes:
- LineChart with two series: Registration rate, Subscription attempt rate.
- Subscription attempt rate uses BQ attempts, not Woo completions, for trend stability. Completions add Woo round-trip cost. Tab 6 has the accurate-completion view.
- For windows > 12 weeks, the chart gets crowded; aggregate to monthly at display time.

## Section: Stuck stages (diagnostic)

The opportunity buckets. Each is a scorecard with an actionable framing.

### Stale Registered Readers

Registered readers with no conversion (subscription or donation) AND no activity in the last 90 days.

```sql
-- All-Woo: find registered users without any subscription or donation
SELECT COUNT(DISTINCT u.ID) AS stale_registered_count
FROM {prefix}users u
WHERE u.ID NOT IN (
  -- Active subscribers
  SELECT customer_id FROM {prefix}wc_orders
  WHERE type = 'shop_subscription'
    AND status IN ('wc-active', 'wc-pending-cancel')
)
AND u.ID NOT IN (
  -- Recent donors
  SELECT o.customer_id FROM {prefix}wc_orders o
  JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
  WHERE o.type = 'shop_order'
    AND opl.product_id IN (:donation_product_ids)
    AND o.date_created_gmt >= DATE_SUB(NOW(), INTERVAL 365 DAY)
)
AND u.ID NOT IN (
  -- Recently active (90 days, from BQ)
  -- This requires a BQ → Woo round trip with active user_ids; document the join
  :recently_active_uids
);
```

Notes:
- "Opportunity bucket" framing in UI: "X readers registered but haven't converted. Consider a re-engagement campaign."
- The 365d donor lookback matches Tab 7's "active donor" definition.
- This is intentionally a count, not a list. Listing individual readers raises privacy concerns; aggregated count is the metric.

### At-Risk Subscribers (recurring, with payment retry scheduled)

Subscribers whose next payment is in retry state — payment failed, retry scheduled.

```sql
SELECT COUNT(DISTINCT o.customer_id) AS at_risk_subscriber_count
FROM {prefix}wc_orders o
JOIN {prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = '_schedule_payment_retry'
JOIN {prefix}wc_order_product_lookup opl ON opl.order_id = o.id
WHERE o.type = 'shop_subscription'
  AND o.status IN ('wc-on-hold', 'wc-active')
  AND opl.product_id NOT IN (:donation_product_ids)
  AND om.meta_value != ''
  AND om.meta_value > NOW();
```

Notes:
- UI framing: "X subscribers have failed payment with retry scheduled. Reach out before retry fails."

### Lapsed Donors (no donation in 365d)

Same metric as Tab 7's "Lapsed Donors" scorecard. Duplicated here for the diagnostic view.

```sql
-- Same query as Tab 7's lapsed_donors metric
```

Notes:
- UI framing: "X donors haven't given in 12 months. Consider a winback campaign."

### Top Pages That Don't Convert (Table)

Pages with high pageviews but low conversion rate among their readers.

```sql
WITH page_metrics AS (
  SELECT
    PARAM_INT('post_id') AS post_id,
    PARAM('page_location') AS page_url,
    PARAM('page_title') AS page_title,
    COUNT(*) AS pageviews,
    COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_readers,
    COUNT(DISTINCT IF(
      event_name = 'np_reader_registered',
      COALESCE(user_id, user_pseudo_id), NULL
    )) AS converters
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'page_view'
    AND PARAM_INT('post_id') IS NOT NULL  -- singular content only
  GROUP BY post_id, page_url, page_title
  HAVING pageviews >= 100  -- minimum threshold to avoid noise
)
SELECT
  post_id,
  page_url,
  page_title,
  pageviews,
  unique_readers,
  SAFE_DIVIDE(converters, unique_readers) AS conversion_rate
FROM page_metrics
ORDER BY pageviews DESC, conversion_rate ASC
LIMIT 25;
```

Notes:
- Filtered to singular content (posts, pages, CPTs) via `post_id IS NOT NULL`. Archives and homepage are excluded — the diagnostic is "which articles don't convert" specifically.
- Sort by pageviews DESC then conversion_rate ASC — surfaces the high-traffic-low-conversion pages.
- The minimum 100 pageviews threshold filters out noise. Adjust per publisher scale.
- UI framing: "These high-traffic pages don't drive registrations. Consider adding a gate or prompt here."
- For canonical post titles (current titles, not whatever was set at page view time), v1.1 may add a wp_posts join via post_id.

## Section: Cross-tab Influenced attribution (duplicated from Tabs 4, 5, 6, 7)

Surfaces the Influenced metrics in one place so publishers don't have to bounce between tabs.

### Influenced Registration Rate (7d lookback)

% of new registrations whose user had ANY gate or prompt impression in the 7 days before registration.

Pattern from Tab 4 and Tab 5 Influenced metrics. Combine both surfaces:

```sql
WITH new_registrations AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    event_timestamp AS reg_ts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'np_reader_registered'
),
gate_or_prompt_exposures AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    event_timestamp AS exposure_ts
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN
    FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @start_date), INTERVAL 7 DAY))
    AND @end_date
    AND PARAM('action') = 'seen'
    AND (
      (event_name = 'np_gate_interaction'
       AND (PARAM('gate_has_registration_block') = 'yes' OR PARAM('gate_has_registration_link') = 'yes'))
      OR
      (event_name = 'np_prompt_interaction'
       AND PARAM('action_type') = 'registration'
       AND PARAM('prompt_has_registration_block') = 'yes')
    )
),
influenced AS (
  SELECT DISTINCT r.uid
  FROM new_registrations r
  JOIN gate_or_prompt_exposures e USING (uid)
  WHERE e.exposure_ts < r.reg_ts
    AND e.exposure_ts >= TIMESTAMP_SUB(TIMESTAMP_MICROS(r.reg_ts), INTERVAL 7 DAY)
)
SELECT
  (SELECT COUNT(*) FROM influenced) /
  NULLIF((SELECT COUNT(DISTINCT uid) FROM new_registrations), 0)
    AS influenced_registration_rate;
```

### Influenced Subscription Rate (14d lookback)

Same pattern but with subscription-intent surfaces and Woo subscription completion. Filter exposures by `gate_has_checkout_button = 'yes'` OR subscription-intent prompts (`action_type = 'registration'` AND `prompt_has_registration_block = 'yes'`). Filter conversions by Woo non-donation subscription orders. Lookback 14 days.

### Influenced Donation Rate (14d lookback)

Same pattern with donation-intent surfaces and donation orders. Lookback 14 days.

### Influenced Newsletter Signup Rate (7d lookback)

Same pattern with newsletter-intent surfaces and `np_newsletter_subscribed` events. Lookback 7 days.

## Open items specific to Tab 3

1. **Cohort retention is expensive.** Both BQ-side and Woo-side queries scan substantial data. Pre-warm via Action Scheduler is mandatory. Plan: refresh weekly, on Monday early morning, cache for the week. Acceptable for v1.

2. **The diagnostic "Stale Registered" metric requires a BQ → Woo round trip with potentially large UID lists.** For publishers with > 10K registered users, this could be slow. Consider materializing the "recently active UIDs" set into the cache table on a schedule.

3. **Cross-upsell metrics (Subscriber → Donor, Donor → Subscriber) only meaningful at scale.** Hide these sections when the publisher has < 50 of either group. UI framing should explain the threshold.

4. **The "Top Pages That Don't Convert" diagnostic** is a prescriptive recommendation engine in disguise. Some publishers will see it as helpful; others as pushy. Worth A/B testing with first cohort of Insights users.

5. **The Ambassador classification problem (NPPD-1619) cascades into Tab 3.** Source attribution PieCharts will misclassify donations from custom subscription products as "subscriber conversions." The Subscriber → Donor cross-upsell funnel underestimates because Ambassador-style products show up in step 1 as subscribers, not in step 2 as donors. Same fix path as Tab 6/7.

6. **Window vs lookback discipline.** Some metrics use the publisher-selected window (`@start_date` to `@end_date`); others extend lookback (90d for first session, 365d for cohort retention, 7-14d for Influenced). UI should display the actual lookback range for each metric in tooltips, since users will be confused otherwise.

7. **Time-to-convert distributions are tail-heavy.** Some readers register years after first session; some donate years after registering. v1 caps at 365d for time-to-subscribe/donate to keep the cumulative curves readable. Document the truncation in UI.

8. **Local wp_posts enrichment available as v1.1 improvement.** `post_id` is now in BQ event params on every singular page view, enabling future joins to local `wp_posts` for canonical post titles, authoritative author/category lookup, and post_type filtering. Used by the "Top Pages That Don't Convert" diagnostic table. Adds a new join pattern (BQ → wp_posts) that v1 metrics don't currently use. v1.1 follow-up.

## Cross-references

- Event reference: `../event-reference.md`
- BQ conventions: `./README.md`
- Schema reference: `./subscription-donation-schema.md`
- Architecture: `../architecture.md`
- Open questions: `../open-questions.md`
- Tab 1 (Audience Overview) for the reach-side inputs: `./tab-1-audience.md`
- Tab 4 (Gates) for Gates-specific Direct/Influenced: `./tab-4-gates.md`
- Tab 5 (Prompts) for Prompts-specific Direct/Influenced: `./tab-5-prompts.md`
- Tab 6 (Subscribers) for subscription details: `./tab-6-subscribers.md`
- Tab 7 (Donors) for donation details: `./tab-7-donors.md`